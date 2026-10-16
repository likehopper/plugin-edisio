import binascii
import importlib
import importlib.util
import json
import logging
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


DAEMON_DIR = Path(__file__).resolve().parents[2] / "resources" / "edisiod"


def load_daemon():
    """Import edisiod.py without its runtime dependencies (pyserial, pyudev, requests)."""
    for name in ("serial", "pyudev", "requests"):
        if name not in sys.modules:
            stub = types.ModuleType(name)
            stub.VERSION = "test"
            stub.__version__ = "test"
            sys.modules[name] = stub
    if str(DAEMON_DIR) not in sys.path:
        sys.path.insert(0, str(DAEMON_DIR))
    return importlib.import_module("edisiod")


edisiod = load_daemon()
logging.disable(logging.CRITICAL)
daemon_globals = sys.modules["globals"]

PID = "0A1B2C3D"


def frame(mid="01", cmd="01", bid="01", pid=PID, bl="1E", rmax="03", rc="00", data=""):
    """Build an Edisio frame: header 6C7663, PID, BID, MID, BL, RMAX, RC, CMD, DATA, trailer 640D0A."""
    return "6C7663" + pid + bid + mid + bl + rmax + rc + cmd + data + "640D0A"


def packet(hex_frame):
    return binascii.unhexlify(hex_frame)


class FakeSerial:
    def __init__(self, data=b""):
        self.buffer = bytearray(data)
        self.written = []

    def read(self):
        if not self.buffer:
            return None
        byte = bytes(self.buffer[:1])
        del self.buffer[:1]
        return byte

    def readbytes(self, number):
        chunk = bytes(self.buffer[:number])
        del self.buffer[:number]
        return chunk

    def write(self, data):
        self.written.append(data)

    def flushOutput(self):
        pass

    def flushInput(self):
        pass


class DaemonTestCase(unittest.TestCase):
    def setUp(self):
        self.com = MagicMock()
        daemon_globals.JEEDOM_COM = self.com
        daemon_globals.KNOWN_DEVICES = {PID: PID}
        daemon_globals.INCLUDE_MODE = False
        edisiod._prevMessage = ""
        edisiod.DimOff_threads.clear()

    def sent(self):
        return [call.args for call in self.com.add_changes.call_args_list]


class GlobalsTests(unittest.TestCase):
    def test_daemon_starts_outside_include_mode(self):
        spec = importlib.util.spec_from_file_location("fresh_globals", DAEMON_DIR / "globals.py")
        fresh = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fresh)
        self.assertIs(fresh.INCLUDE_MODE, False)
        self.assertEqual(fresh.KNOWN_DEVICES, {})


class FrameValidationTests(unittest.TestCase):
    def test_accepts_valid_frame_with_or_without_spaces(self):
        self.assertTrue(edisiod.test_edisio(frame()))
        spaced = " ".join(frame()[i:i + 2] for i in range(0, len(frame()), 2))
        self.assertTrue(edisiod.test_edisio(spaced))

    def test_rejects_invalid_frames(self):
        valid = frame()
        cases = {
            "not hex": valid[:-2] + "ZZ",
            "odd length": valid + "0",
            "too short": "6C7663640D0A",
            "bad first byte": "6D" + valid[2:],
            "bad second byte": "6C77" + valid[4:],
            "bad third byte": "6C7664" + valid[6:],
            "bad last byte": valid[:-2] + "0B",
            "bad byte -2": valid[:-4] + "0E0A",
            "bad byte -3": valid[:-6] + "650D0A",
        }
        for label, message in cases.items():
            with self.subTest(label):
                self.assertFalse(edisiod.test_edisio(message))


class DecodePacketTests(DaemonTestCase):
    def test_button_press_of_known_device(self):
        edisiod.decodePacket(packet(frame(mid="01", cmd="01", bid="02")))
        self.assertEqual(self.sent(), [(
            "devices::" + PID + "01" + "01" + "02",
            {"id": PID, "battery": "90", "mid": "01", "raw": frame(mid="01", cmd="01", bid="02"), "bt": "02", "value": "1"},
        )])

    def test_value_mapping(self):
        expected = {"01": "1", "02": "0", "03": "toggle", "09": "1", "FA": "100", "99": ""}
        for cmd, value in expected.items():
            with self.subTest(cmd=cmd):
                self.com.reset_mock()
                edisiod._prevMessage = ""
                edisiod.decodePacket(packet(frame(mid="02", cmd=cmd)))
                self.assertEqual(self.sent()[0][1]["value"], value)

    def test_simple_value_models(self):
        for mid in ("01", "02", "03", "04", "05", "07", "09", "0C", "0F", "17", "18"):
            with self.subTest(mid=mid):
                self.com.reset_mock()
                edisiod._prevMessage = ""
                edisiod.decodePacket(packet(frame(mid=mid, cmd="02")))
                change = self.sent()[0][1]
                self.assertEqual((change["bt"], change["value"]), ("01", "0"))

    def test_ignored_models(self):
        for mid in ("06", "0B", "0D", "0E", "10", "11", "12", "13", "14", "15", "16",
                    "1B", "1E", "1F", "20", "21", "22", "23"):
            with self.subTest(mid=mid):
                edisiod._prevMessage = ""
                edisiod.decodePacket(packet(frame(mid=mid)))
        self.com.add_changes.assert_not_called()

    def test_unhandled_model_without_payload_is_not_sent(self):
        edisiod.decodePacket(packet(frame(mid="0A")))
        self.com.add_changes.assert_not_called()

    def test_temperature(self):
        edisiod.decodePacket(packet(frame(mid="08", data="E803")))
        change = self.sent()[0][1]
        self.assertEqual(change["temperature"], "10.0")
        self.assertNotIn("bt", change)

    def test_invalid_temperature_is_dropped(self):
        edisiod.decodePacket(packet(frame(mid="08")))
        self.com.add_changes.assert_not_called()

    def test_window_sensor_states(self):
        for cmd, state in (("0B", "1"), ("0A", "2"), ("09", "3")):
            with self.subTest(cmd=cmd):
                self.com.reset_mock()
                edisiod._prevMessage = ""
                edisiod.decodePacket(packet(frame(mid="1D", cmd=cmd)))
                self.assertEqual(self.sent()[0][1]["state"], state)

    def test_unknown_device_is_ignored_outside_include_mode(self):
        edisiod.decodePacket(packet(frame(pid="11111111")))
        self.com.add_changes.assert_not_called()

    def test_unknown_device_is_reported_in_include_mode(self):
        daemon_globals.INCLUDE_MODE = True
        edisiod.decodePacket(packet(frame(pid="11111111")))
        self.assertEqual(self.sent()[0][1]["id"], "11111111")

    def test_repeated_frame_is_decoded_once(self):
        message = packet(frame())
        edisiod.decodePacket(message)
        edisiod.decodePacket(message)
        self.assertEqual(len(self.sent()), 1)

    def test_invalid_frame_is_dropped(self):
        edisiod.decodePacket(packet(frame()[:-2] + "0B"))
        self.com.add_changes.assert_not_called()


class DimmerReleaseTests(DaemonTestCase):
    def test_long_press_sends_up_then_down_when_timer_expires(self):
        timer = MagicMock()
        with patch.object(edisiod, "TimerReset", return_value=timer) as factory:
            edisiod.decodePacket(packet(frame(mid="01", cmd="07", bid="03")))
        key = PID + "03"
        self.assertIs(edisiod.DimOff_threads[key], timer)
        timer.start.assert_called_once()
        self.assertEqual(self.sent()[0][1]["value"], "up")

        args = factory.call_args.kwargs["args"]
        self.assertEqual(args[1]["value"], "down")
        factory.call_args.kwargs["function"](*args)
        self.assertEqual(self.sent()[-1], ("devices::" + key, args[1]))
        self.assertNotIn(key, edisiod.DimOff_threads)

    def test_long_press_repeat_resets_existing_timer(self):
        timer = MagicMock()
        edisiod.DimOff_threads[PID + "03"] = timer
        edisiod._timerDatetime = edisiod._timerDatetime.min
        edisiod.decodePacket(packet(frame(mid="01", cmd="07", bid="03")))
        timer.reset.assert_called_once_with(1)


class SerialReadTests(DaemonTestCase):
    def read(self, data):
        with patch.object(edisiod, "jeedom_serial", FakeSerial(data)), \
                patch.object(edisiod, "decodePacket") as decode:
            edisiod.read_edisio()
        return decode

    def test_reads_a_16_byte_frame(self):
        decode = self.read(packet(frame()))
        decode.assert_called_once_with(packet(frame()))

    def test_reads_longer_frame_until_trailer(self):
        message = packet(frame(mid="08", data="E803"))
        decode = self.read(message + b"\x6c")
        decode.assert_called_once_with(message)

    def test_ignores_bytes_outside_a_frame(self):
        self.read(b"\x00").assert_not_called()
        self.read(b"").assert_not_called()


class JeedomSerialTests(unittest.TestCase):
    def serial(self, port):
        link = edisiod.jeedom_serial(device="/dev/null", rate=9600)
        link.port = port
        return link

    def test_uses_pyserial_3_api(self):
        port = MagicMock(spec=["in_waiting", "read", "reset_input_buffer", "reset_output_buffer"])
        port.in_waiting = 0
        link = self.serial(port)
        self.assertIsNone(link.read())
        port.in_waiting = 1
        port.read.return_value = b"\x6c"
        self.assertEqual(link.read(), b"\x6c")
        link.flushInput()
        link.flushOutput()
        port.reset_input_buffer.assert_called_once_with()
        port.reset_output_buffer.assert_called_once_with()

    def test_readbytes_reads_the_requested_length_at_once(self):
        port = MagicMock()
        port.read.return_value = b"\x01\x02\x03"
        self.assertEqual(self.serial(port).readbytes(3), b"\x01\x02\x03")
        port.read.assert_called_once_with(3)

    def test_readbytes_returns_empty_bytes_on_error(self):
        for error in (OSError("boom"), IOError("boom")):
            with self.subTest(error=type(error).__name__):
                port = MagicMock()
                port.read.side_effect = error
                self.assertEqual(self.serial(port).readbytes(3), b"")


class SerialWriteTests(DaemonTestCase):
    def test_valid_frame_is_written_three_times(self):
        fake = FakeSerial()
        with patch.object(edisiod, "jeedom_serial", fake), patch.object(edisiod.time, "sleep"):
            edisiod.send_edisio(frame())
        self.assertEqual(fake.written, [packet(frame())] * 3)

    def test_invalid_frame_is_not_written(self):
        fake = FakeSerial()
        with patch.object(edisiod, "jeedom_serial", fake), patch.object(edisiod.time, "sleep"):
            edisiod.send_edisio("6C7663")
        self.assertEqual(fake.written, [])


class SocketMessageTests(DaemonTestCase):
    def handle(self, message):
        edisiod.JEEDOM_SOCKET_MESSAGE.put(json.dumps(message))
        edisiod.read_socket()

    def setUp(self):
        super().setUp()
        self.apikey = patch.object(edisiod, "_apikey", "secret")
        self.apikey.start()
        self.addCleanup(self.apikey.stop)

    def test_add_and_remove_device(self):
        self.handle({"apikey": "secret", "cmd": "add", "device": {"id": "22222222"}})
        self.assertIn("22222222", daemon_globals.KNOWN_DEVICES)
        self.handle({"apikey": "secret", "cmd": "remove", "device": {"id": "22222222"}})
        self.assertNotIn("22222222", daemon_globals.KNOWN_DEVICES)

    def test_wrong_apikey_is_rejected(self):
        self.handle({"apikey": "wrong", "cmd": "add", "device": {"id": "22222222"}})
        self.assertNotIn("22222222", daemon_globals.KNOWN_DEVICES)

    def test_include_mode(self):
        self.handle({"apikey": "secret", "cmd": "include_mode", "state": "1"})
        self.assertTrue(daemon_globals.INCLUDE_MODE)
        self.com.send_change_immediate.assert_called_with({"include_mode": "1"})
        self.handle({"apikey": "secret", "cmd": "include_mode", "state": "0"})
        self.assertFalse(daemon_globals.INCLUDE_MODE)

    def test_send_accepts_a_list_or_a_single_frame(self):
        with patch.object(edisiod, "send_edisio") as send:
            self.handle({"apikey": "secret", "cmd": "send", "data": ["A", "B"]})
            self.handle({"apikey": "secret", "cmd": "send", "data": "C"})
        self.assertEqual([call.args[0] for call in send.call_args_list], ["A", "B", "C"])


if __name__ == "__main__":
    unittest.main()

# Plugin Jeedom Edisio

Pilote les modules Edisio (émetteurs, récepteurs, sondes) à travers la clé USB Edisio.

- Documentation utilisateur : [docs/fr_FR/index.md](docs/fr_FR/index.md)
- Changelog : [docs/fr_FR/changelog.md](docs/fr_FR/changelog.md)

## Developer guide

### Layout

| Path | Role |
|---|---|
| `core/class/edisio.class.php` | Jeedom equipment and command classes: daemon management, inclusion, model configuration, sending commands to the daemon |
| `core/php/edisio.inc.php` | Pure helpers without Jeedom dependencies (frame building, model definition lookup, ban list), covered by `tests/php` |
| `core/php/jeeEdisio.php` | Callback called by the daemon with decoded frames |
| `core/config/devices/<code>/` | One folder per model: `<code>.json` (commands) and its images (`<code>.jpg`, `<code>_<colour>.jpg`) |
| `resources/edisiod/edisiod.py` | Daemon: reads the USB key, decodes frames, sends commands received on its local socket |
| `resources/edisiod/jeedom/jeedom.py` | Jeedom daemon helpers (callback, serial port, socket) |
| `plugin_info/` | Metadata (`info.json`), install/update hooks, configuration page, `packages.json` |

User-facing strings stay in French inside `{{…}}` and `__()`: French is the Jeedom source language for translations.

### Frames

An Edisio frame is `6C 76 63` + PID (4 bytes) + BID + MID + BL + RMAX + RC + CMD + optional DATA + `64 0D 0A` (at least 16 bytes). The MID is the model code, i.e. the folder name in `core/config/devices`. Action commands store the frame template in their `logicalId`: `#ID#`, `#GROUP#`, `#slider#` and `#color#` are replaced by `edisio_build_frames()`, and several frames are separated by `&&`.

### Tests

The tests use only the PHP and Python standard libraries: no Jeedom, USB key or Python package needed (pyserial, pyudev and requests are replaced by stubs).

```sh
for test in tests/php/*_test.php; do php "$test"; done
python3 -m unittest discover -s tests/python
```

| Suite | Covers |
|---|---|
| `tests/php/devices_test.php` | Every model folder holds `<code>.json` keyed by its folder name, and every action command builds valid frames |
| `tests/php/frames_test.php` | Frame building: id, group, multiple frames, slider level |
| `tests/php/ban_test.php` | Ban list parsing |
| `tests/python/test_edisiod.py` | Daemon: frame validation, decoding per MID, duplicate filter, dimmer release, serial read/write, socket commands, arguments |

GitHub Actions runs them on every push and pull request (PHP 7.4 and 8.2, Python 3.9, 3.11 and 3.13 with deprecation warnings as errors), together with the Jeedom plugin checks (`jeedom/workflows`).

Logic that can be expressed without Jeedom belongs in `core/php/edisio.inc.php` with a test; the class only wires it to Jeedom.

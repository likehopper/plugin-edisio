# Changelog Edisio

>**IMPORTANT**
>
>Pour rappel s'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte

# 16/10/2026

- La liste « Bannir les IDs » de la configuration est enfin prise en compte (espaces, retours à la ligne ou virgules comme séparateurs)
- Message d'erreur quand une commande ou l'inclusion échoue parce que le démon ne répond pas
- Démon : une erreur de lecture série n'arrête plus le démon
- Démon : correction de la lecture série en cas d'erreur (trame corrompue ou arrêt)
- Démon : anti-doublon insensible aux changements d'heure système, compatibilité Python 3.12+ (Debian 13)
- Variateur : niveau borné entre 0 et 100
- Un module refusé à l'inclusion n'empêche plus le traitement des autres modules
- Correction des images de repli (page Santé, équipements sans image)
- Mise à jour du plugin sans erreur pour les équipements sans modèle
- Moins d'accès disque à l'affichage des équipements et pendant le cron journalier
- Ajout de tests automatiques (PHP et Python) et de la vérification continue GitHub

# 21/03/2025

- Correction d'un bug dans le démon (`AttributeError: 'dict' object has no attribute 'has_key'`)

# 02/02/2025

- Correction d'une fuite mémmoire sous Debian 11
- Compatibilité Debian 12
- Correction de bugs mineurs
- Version minimum Jeedom 4.4
- Version minimum Debian 11

# 06/08/2020

- Correction de bugs

# 10/01/2020

- Correction d'un soucis sur l'affichage des images et de modeles

# 11/09/2019

- Adaptation pour jeedom v4

# 13/08/2019

- Support de PHP 7.3

# 15/01/2018

- Correction d'une erreur 500 lors de la remonté de valeur de batterie

# 18/10/2018

- Correction d'un bug si le niveau de log est sur aucun
- Suppression des boutons supprimer/sauvegarder en double

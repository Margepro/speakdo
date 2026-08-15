#!/usr/bin/env bash
set -euo pipefail

MODULE_NAME="speakdo"
MODULE_PATH="htdocs/${MODULE_NAME}"
DESCRIPTOR="${MODULE_PATH}/core/modules/modSpeakdo.class.php"

echo "=== Création du package Dolibarr SpeakDo ==="

# Vérification du module
if [ ! -d "$MODULE_PATH" ]; then
    echo "ERREUR : dossier ${MODULE_PATH} introuvable."
    exit 1
fi

# Vérification du descripteur
if [ ! -f "$DESCRIPTOR" ]; then
    echo "ERREUR : descripteur de module introuvable :"
    echo "  ${DESCRIPTOR}"
    exit 1
fi

# Extraction de la version depuis :
# $this->version = '1.0.0';
# ou
# $this->version = "1.0.0";
VERSION=$(
    sed -nE \
        "s/^[[:space:]]*\\\$this->version[[:space:]]*=[[:space:]]*['\"]([^'\"]+)['\"][[:space:]]*;.*/\1/p" \
        "$DESCRIPTOR" \
    | head -n 1
)

if [ -z "$VERSION" ]; then
    echo "ERREUR : impossible d'extraire la version depuis :"
    echo "  ${DESCRIPTOR}"
    exit 1
fi

# Sécurité minimale sur le nom de version
if [[ ! "$VERSION" =~ ^[0-9A-Za-z._+-]+$ ]]; then
    echo "ERREUR : version invalide détectée : ${VERSION}"
    exit 1
fi

ZIP_NAME="module_${MODULE_NAME}-${VERSION}.zip"

echo "Module  : ${MODULE_NAME}"
echo "Version : ${VERSION}"
echo "Package : ${ZIP_NAME}"
echo

# Supprime un éventuel package de cette version déjà présent
rm -f "$ZIP_NAME"

# Création de l'archive
zip -r "$ZIP_NAME" "$MODULE_PATH" \
    -x "*/.git/*" \
       "*/.git" \
       "*/.github/*" \
       "*/.github" \
       "*/.gitignore" \
       "*/.gitattributes" \
       "*/.DS_Store"

echo
echo "=== Vérification des fichiers Git ==="

if unzip -l "$ZIP_NAME" | grep -E '/\.git(/|$)|/\.github(/|$)|\.gitignore$|\.gitattributes$'; then
    echo
    echo "ERREUR : des fichiers Git sont présents dans le ZIP."
    rm -f "$ZIP_NAME"
    exit 1
fi

echo
echo "=== Package créé avec succès ==="
echo
echo "${ZIP_NAME}"
echo

echo "Structure du package :"
unzip -l "$ZIP_NAME" | head -30

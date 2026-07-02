# Studiz — Railway container image.
# Local dev remains XAMPP (Apache on Windows) per CLAUDE.md; this image is
# ONLY for the Railway/production target. It packages PHP 8.2 + Apache +
# the Python text-extraction toolchain (PyMuPDF, Tesseract OCR, python-docx,
# python-pptx, openpyxl, xlrd) into one container, since Railway's Nixpacks
# auto-builder has no preset that combines all of these.
FROM php:8.2-apache

# ── System dependencies ──────────────────────────────────────────────────
# python3/pip          — runs python/extract_text.py via proc_open
# tesseract-ocr(+eng)  — OCR backend used by pytesseract for scanned pages/images
# libcurl4-openssl-dev — headers needed to compile the PHP curl extension
# libonig-dev          — headers needed to compile the PHP mbstring extension
RUN apt-get update && apt-get install -y --no-install-recommends \
      python3 \
      python3-pip \
      tesseract-ocr \
      tesseract-ocr-eng \
      libcurl4-openssl-dev \
      libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────
# pdo_mysql — db.php's PDO connection (mysql: DSN)
# mbstring  — mb_substr() calls throughout api_gateway.php/store.php/Auth.php
# curl      — every OpenAI/YouTube/webpage-fetch call (OpenAI.php, api_gateway.php)
RUN docker-php-ext-install pdo_mysql mbstring curl

RUN a2enmod headers

# ── Python dependencies ──────────────────────────────────────────────────
COPY python/requirements.txt /tmp/requirements.txt
# Debian bookworm's pip refuses system-wide installs by default (PEP 668);
# --break-system-packages is safe here since this container has no other
# Python workload to conflict with. Falls back cleanly on older bases
# (bullseye) where the flag doesn't exist and isn't needed.
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt \
    || pip3 install --no-cache-dir --break-system-packages -r /tmp/requirements.txt

# ── App code ──────────────────────────────────────────────────────────────
COPY . /var/www/html

# The XAMPP-only "/studizz/" URL prefix (see CLAUDE.md — required locally
# because the app is served from a /studizz/ subfolder under htdocs) is
# stripped here, in the IMAGE only. Railway serves this app at the domain
# root, so asset/API paths must be root-relative instead. The actual repo
# files copied above are untouched by this — local XAMPP dev is unaffected.
RUN sed -i 's#/studizz/#/#g' \
      /var/www/html/public/js/app.js \
      /var/www/html/public/sections/head.php \
      /var/www/html/public/sections/page-home.php \
      /var/www/html/public/sections/scripts.php

# ── Web server + PHP config ──────────────────────────────────────────────
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-studiz-overrides.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Directories the app writes to at runtime (uploads are processed then
# @unlink()-ed immediately — see api_gateway.php — so no volume is needed).
RUN mkdir -p /var/www/html/uploads/temp /var/www/html/uploads/processed /var/www/html/backend/logs \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/backend/logs

# PYTHON_BIN (see backend/config/constants.php): local dev falls back to a
# hardcoded Windows path; here it's pinned to the container's real interpreter.
ENV PYTHON_BIN=/usr/bin/python3

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

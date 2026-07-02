# STUDIZ — Complete Local Setup Guide
# From zero to running on your laptop

---

## WHAT YOU NEED TO DOWNLOAD FIRST

| Software | Where to get it | Why |
|---|---|---|
| XAMPP | https://www.apachefriends.org | PHP 8.x + MySQL + Apache in one installer |
| Python 3 | https://www.python.org/downloads | Runs the text extraction script |
| Tesseract OCR | See Step 3 below | Reads text from scanned images |

---

## STEP 1 — Install XAMPP

1. Go to https://www.apachefriends.org and download **XAMPP for your OS**
2. Run the installer — keep all default options
3. Open **XAMPP Control Panel**
4. Click **Start** next to both **Apache** and **MySQL**
5. Both should show a green highlight when running

---

## STEP 2 — Place the Studiz project files

1. Find your XAMPP installation folder:
   - **Windows:** `C:\xampp\htdocs\`
   - **Mac:** `/Applications/XAMPP/htdocs/`
2. Create a new folder inside htdocs called `studiz`
3. Copy **everything from the zip you downloaded** into that folder
4. Also copy the `uploads/` folder (from this zip) into it

Your final structure should look like:
```
htdocs/
└── studiz/
    ├── public/
    ├── backend/
    ├── python/
    ├── database/
    └── uploads/
        ├── temp/
        └── processed/
```

---

## STEP 3 — Install Tesseract OCR

**Windows:**
1. Download the installer from:
   https://github.com/UB-Mannheim/tesseract/wiki
2. Run it — install to the default path: `C:\Program Files\Tesseract-OCR\`
3. During install, tick **"Add to PATH"**
4. After install, verify it works — open Command Prompt and type:
   ```
   tesseract --version
   ```
   You should see a version number.

**Mac:**
```bash
# Install Homebrew first if you don't have it:
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Then install Tesseract:
brew install tesseract
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt-get update
sudo apt-get install tesseract-ocr -y
```

---

## STEP 4 — Set up Python and install libraries

**Verify Python is installed:**
```bash
python3 --version
# Should show Python 3.x.x
```

**Install the required Python libraries:**
```bash
pip install -r python/requirements.txt
```

On some systems you may need:
```bash
pip3 install -r python/requirements.txt
```

**Verify it worked:**
```bash
python3 -c "import fitz, pytesseract, PIL, docx, pptx, openpyxl, xlrd; print('All OK')"
# Should print: All OK
```

---

## STEP 5 — Create the database

1. Open your browser and go to: **http://localhost/phpmyadmin**
2. Log in (default: username `root`, password blank)
3. Click **"New"** in the left sidebar
4. Name the database: `studiz_db` — click **Create**
5. Click the **`studiz_db`** database in the sidebar
6. Click the **SQL** tab at the top
7. Open the file `database/schema.sql` from your project folder
8. Copy ALL the text inside it
9. Paste it into the SQL box in phpMyAdmin
10. Click **Go**
11. You should see all 6 tables created with green checkmarks

---

## STEP 6 — Set your API keys

1. Open the file `SET_YOUR_KEYS_HERE.env` in any text editor (Notepad, VS Code, etc.)
2. Get your **OpenAI API key** from: https://platform.openai.com/api-keys
3. Get your **YouTube Data API v3 key** from: https://console.developers.google.com
   - Create a project → Enable "YouTube Data API v3" → Create credentials → API Key
4. Now open XAMPP's Apache config file:
   - **Windows:** `C:\xampp\apache\conf\httpd.conf`
   - **Mac:** `/Applications/XAMPP/etc/httpd.conf`
5. Scroll to the very bottom and add these lines:
   ```
   SetEnv OPENAI_API_KEY "sk-your-actual-key-here"
   SetEnv YOUTUBE_API_KEY "AIza-your-actual-key-here"
   SetEnv STUDIZ_DB_HOST "127.0.0.1"
   SetEnv STUDIZ_DB_PORT "3306"
   SetEnv STUDIZ_DB_NAME "studiz_db"
   SetEnv STUDIZ_DB_USER "root"
   SetEnv STUDIZ_DB_PASS ""
   SetEnv STUDIZ_ORIGIN "http://localhost"
   ```
6. Save the file
7. Go back to XAMPP Control Panel and click **Stop** then **Start** on Apache

---

## STEP 7 — Fix the Python path in constants.php

Open `backend/config/constants.php` and find this line:

```php
define('PYTHON_BIN', '/usr/bin/python3');
```

Change it to match your system:

- **Windows:** `define('PYTHON_BIN', 'C:/Users/YourName/AppData/Local/Programs/Python/Python312/python.exe');`
  (Find your actual path by running `where python` in Command Prompt)
- **Mac/Linux:** Leave it as `/usr/bin/python3` or run `which python3` to confirm

Also update the upload path in the same file:

```php
define('UPLOAD_TMP_DIR', __DIR__ . '/../../uploads/temp/');
```
This should already work. Leave it unless you get upload errors.

---

## STEP 8 — Open Studiz in your browser

Go to: **http://localhost/studiz/public/**

You should see the Studiz homepage. The onboarding modal will appear on first visit.

---

## QUICK TROUBLESHOOTING

| Problem | Fix |
|---|---|
| Blank white page | Check Apache is running in XAMPP |
| "Database connection failed" | Confirm MySQL is running in XAMPP, check credentials in httpd.conf |
| "AI service not configured" | Your OPENAI_API_KEY env variable isn't set — restart Apache after editing httpd.conf |
| File upload fails | Check that `uploads/temp/` folder exists and Apache has write permission to it |
| OCR returns no text | Confirm Tesseract is installed and on your PATH (`tesseract --version` works) |
| Python script fails | Confirm `pip install pymupdf pytesseract Pillow` ran without errors |

---

## YOU'RE DONE

Your Studiz instance is fully running locally. When you're ready to deploy to your VPS, follow OPTION B in the `SET_YOUR_KEYS_HERE.env` file for the Nginx + PHP-FPM environment variable setup.

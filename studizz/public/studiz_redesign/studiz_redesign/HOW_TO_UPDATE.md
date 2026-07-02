# STUDIZ REDESIGN — How to Update Your Project Folder
=====================================================

## WHAT'S IN THIS ZIP

```
studiz_redesign/
├── HOW_TO_UPDATE.md          ← this file
└── public/
    ├── index.php             ← replaces your old index.html (rename/delete the old one)
    ├── css/
    │   └── linear.css        ← new design system stylesheet
    └── sections/             ← all page sections (PHP includes)
        ├── head.php
        ├── modals.php
        ├── navbar.php
        ├── page-home.php
        ├── page-features.php
        ├── page-study.php
        ├── page-store.php
        ├── page-chatbot.php
        ├── footer.php
        └── scripts.php
```

---

## STEP-BY-STEP: HOW TO UPDATE YOUR PROJECT

### Step 1 — Open your project folder
Navigate to where your Studiz project lives:
```
C:\xampp\htdocs\studiz\
```

### Step 2 — Delete (or rename) your old index.html
Your old file was `public/index.html`. You need to remove it because
PHP won't serve `index.php` if `index.html` exists alongside it.

Either:
- Delete:  `C:\xampp\htdocs\studiz\public\index.html`
- OR rename it to: `index.html.bak` (safe backup)

### Step 3 — Copy the new files in
From this zip, copy these into your project:

| Copy this from the zip              | Into this location in your project          |
|-------------------------------------|---------------------------------------------|
| public/index.php                    | C:\xampp\htdocs\studiz\public\index.php     |
| public/css/linear.css               | C:\xampp\htdocs\studiz\public\css\linear.css|
| public/sections/ (entire folder)    | C:\xampp\htdocs\studiz\public\sections\     |

### Step 4 — Add your logo (optional but recommended)
Open this file:
```
public/sections/navbar.php
```
Find this line (it has a big comment box around it):
```html
<img src="" alt="Studiz logo" class="lnr-logo-img" ...>
```
Change the `src=""` to your logo file path:
```html
<img src="/public/assets/logo.png" alt="Studiz logo" class="lnr-logo-img" ...>
```
If you don't have a logo yet, leave it — the text "Studiz" shows automatically as a fallback.

### Step 5 — Keep your existing files untouched
You do NOT touch any of these — they are unchanged:
```
backend/         ← all PHP gateways and services
python/          ← extract_text.py
database/        ← schema.sql
public/js/app.js ← JavaScript logic
uploads/         ← upload directories
```

### Step 6 — Open in browser
Go to:
```
http://localhost/studiz/public/
```
Apache will now serve index.php automatically.

---

## TROUBLESHOOTING

| Problem | Fix |
|---|---|
| Page looks unstyled | Check that `linear.css` is in `public/css/` and Apache is running |
| Blank page / PHP error | Make sure you deleted or renamed the old `index.html` |
| Logo not showing | Either add a valid image path in navbar.php or leave src="" for text fallback |
| Old design still showing | Hard refresh: press Ctrl + Shift + R in your browser |
| PHP include errors | Make sure all files from `sections/` folder were copied correctly |

---

## NOTES
- Your `app.js` is NOT replaced. All existing JavaScript logic still works.
- All form IDs, button IDs, and data-page attributes are identical to the original.
- The backend PHP files are completely untouched.
- The new stylesheet is `linear.css` — the old `style.css` can stay in place, it won't interfere.

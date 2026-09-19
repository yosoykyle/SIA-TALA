# Developer Guide: Gmail SMTP Setup & Terminal Testing

This guide provides the complete setup for configuring Gmail SMTP in Laravel, along with terminal-based verification commands to test email delivery.

---

## 1. Credentials & Google App Password

Google requires a 16-character **App Password** for SMTP. Standard Google account passwords are not accepted.

### Option A: Using the Shared Development Mailbox
If you are an authorized TALA contributor and need access to the shared development mailbox:
* **Contact:** Reach out to `kylefbaluyot@iskolarngbayan.pup.edu.ph` to securely receive the development SMTP credentials.

### Option B: Using Your Own Gmail Account
1. Log in to your Google Account.
2. Turn ON **2-Step Verification**: [https://myaccount.google.com/signinoptions/two-step-verification](https://myaccount.google.com/signinoptions/two-step-verification).
3. Open the App Passwords page: [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords).
4. Under **App name**, enter `TALA` and click **Create**.
5. Copy the generated 16-character code. **Remove all spaces** (must be entered as a single 16-character string).

---

## 2. Configure Environment (`.env`)

Add the following mail configuration to your local `.env` file:

```env
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_authorized_email@gmail.com
MAIL_PASSWORD=[REDACTED — Contact kylefbaluyot@iskolarngbayan.pup.edu.ph for shared credentials or generate your own App Password]
MAIL_FROM_ADDRESS=your_authorized_email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

> **Important:** Do not wrap `MAIL_PASSWORD` in spaces or hyphens.

---

## 3. Clear Configuration Cache

Whenever `.env` is modified, clear Laravel's configuration cache:

```powershell
php artisan config:clear
```

---

## 4. Terminal-Based Verification Methods

### Method 1: Interactive One-Liner via Tinker (Recommended)
Send a test email directly from PowerShell and verify the SMTP handshake:

```powershell
php artisan tinker --execute 'dump(Mail::raw("This is a test email from TALA.", function ($m) { $m->to("your_personal_email@example.com")->subject("SMTP Test"); }));'
```

#### What Success Looks Like:
* The command dumps an `Illuminate\Mail\SentMessage` object.
* Google returns `235 2.7.0 Accepted` and `250 2.0.0 OK`.

---

### Method 2: Test Network Port Reachability
Verify that your network or ISP allows outbound traffic on SMTP port `587`:

```powershell
Test-NetConnection -ComputerName smtp.gmail.com -Port 587
```
* **Success:** `TcpTestSucceeded : True`

---

## 5. Troubleshooting Matrix

| Error | Cause | Fix |
| :--- | :--- | :--- |
| **`535 5.7.8 BadCredentials`** | Google rejected username/password. | Generate a fresh App Password at `myaccount.google.com/apppasswords` or contact `kylefbaluyot@iskolarngbayan.pup.edu.ph`. Ensure spaces are removed. |
| **`Connection timed out`** | Port 587 blocked by network/firewall. | Run `Test-NetConnection -ComputerName smtp.gmail.com -Port 587` to verify firewall settings. |
| **Changes not applying** | Laravel config cache is stale. | Run `php artisan config:clear`. |

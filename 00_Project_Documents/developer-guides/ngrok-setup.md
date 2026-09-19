# Developer Guide: Exposing Localhost with ngrok

This guide covers installing ngrok via the Microsoft Store and exposing your local Laravel application for webhook testing and remote collaboration.

---

## 1. Installation (Microsoft Store)

1. Open the official [ngrok Microsoft Store Listing](https://apps.microsoft.com/detail/9mvs1j51gmk6?hl=en-US&gl=PH).
2. Click **Install** (or **Get in Store app**).
3. Follow the Windows prompts to complete installation.

### Verify Installation
Open a new PowerShell window and run:
```powershell
ngrok version
```
If installed properly, it prints the version (e.g., `ngrok version 3.x.x`).

---

## 2. One-Time Setup: Authenticate ngrok

ngrok requires a free account to generate public tunnels.

1. Go to [https://dashboard.ngrok.com/signup](https://dashboard.ngrok.com/signup) and create an account.
2. Go to the **Your Authtoken** page: [https://dashboard.ngrok.com/get-started/your-authtoken](https://dashboard.ngrok.com/get-started/your-authtoken).
3. Copy your unique token.
4. In PowerShell, connect your token:
   ```powershell
   ngrok config add-authtoken YOUR_AUTHTOKEN_HERE
   ```
This saves credentials to your local user profile (`%LOCALAPPDATA%\ngrok\ngrok.yml`).

---

## 3. Preparing Your Laravel Application

Before exposing your project, ensure assets load smoothly over the public link:

### 1. Compile Frontend Assets
If you run `npm run dev`, Vite serves styles and scripts from `localhost:5173`, which outside users cannot access. Compile them statically:
```powershell
npm run build
```

### 2. Configure Trusted Proxies (Laravel 11 & 12)
Because ngrok acts as an SSL reverse proxy, ensure Laravel trusts upstream headers in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})
```

---

## 4. Exposing Localhost (Step-by-Step)

You will need **2 separate terminal windows**:

### Terminal 1: Start Your Laravel App
In your project root directory, start the local server:

```powershell
composer run dev
```
*(Or `php artisan serve`)*

Your app is now running locally at `http://127.0.0.1:8000`.

---

### Terminal 2: Start the ngrok Tunnel
In a second PowerShell window, run the command to expose port `8000`:

```powershell
ngrok http 8000 --host-header="localhost:8000"
```

> **Why `--host-header="localhost:8000"`?**  
> This flag rewrites incoming HTTP Host headers so your local Laravel server treats requests identically to regular localhost traffic.

---

## 5. What You See in Terminal 2

When the tunnel starts, ngrok displays a status screen:

```text
Forwarding   https://xxxx-xx-xx-xx.ngrok-free.app -> http://localhost:8000
```

* **Your Public URL:** The `https://xxxx-xx-xx-xx.ngrok-free.app` link under **Forwarding**.
* **Inspect Traffic (Live Dashboard):** Open `http://127.0.0.1:4040` in your browser to inspect incoming HTTP requests, payloads, headers, and responses in real time.

---

## 6. Accessing Your Site & The Warning Page

When visiting the ngrok link for the first time, ngrok displays a free-tier warning banner ("You are about to visit...").
* Click **"Visit Site"** to proceed to your application.
* Automated API / webhook calls bypass this warning automatically or via the header `ngrok-skip-browser-warning: true`.

---

## 7. How to Stop Exposing

* To terminate the tunnel, switch to **Terminal 2** and press **`Ctrl + C`**.

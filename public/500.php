<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Errore interno – BOB</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(180deg, #0f172a, #020617);
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .container {
            text-align: center;
            max-width: 520px;
            padding: 40px;
        }

        .logo {
            margin-bottom: 30px;
        }

        .logo img {
            height: 170px;
            opacity: 0.95;
        }

        h1 {
            font-size: 64px;
            margin: 0;
            font-weight: 800;
            color: #f87171;
        }

        h2 {
            margin-top: 10px;
            font-size: 22px;
            font-weight: 600;
            color: #f8fafc;
        }

        p {
            margin-top: 18px;
            font-size: 15px;
            line-height: 1.6;
            color: #cbd5f5;
        }

        .actions {
            margin-top: 35px;
        }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            background: #2563eb;
            color: #fff;
            transition: background 0.2s ease;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .footer {
            margin-top: 40px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Logo BOB -->
    <div class="logo">
        <img src="https://bob.csmontaggi.it/includes/template/dist/images/logo.png" alt="BOB">
    </div>

    <h1>500</h1>
    <h2>Errore interno</h2>

    <p>
        Si è verificato un problema inatteso.<br>
        BOB è operativo, ma questa operazione non è andata a buon fine.
    </p>

    <p>
        Se l’errore persiste, contatta l’amministratore indicando
        data e operazione eseguita.
    </p>

    <div class="actions">
        <a href="/" class="btn">Torna alla Dashboard</a>
    </div>

    <div class="footer">
        BOB
    </div>
</div>

</body>
</html>

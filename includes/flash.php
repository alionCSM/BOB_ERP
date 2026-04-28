<?php if (isset($_GET['success']) && !empty($_GET['success'])): ?>
    <div id="toast-success"
         style="
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%) scale(0.95);
            background-color: #16a34a !important;
            color: white !important;
            padding: 12px 24px;
            border-radius: 9999px;
            font-weight: 600;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s ease-out;
            text-align: center;
            font-size: 14px;
         ">
        <?= htmlspecialchars($_GET['success']) ?>
    </div>

    <script src="/assets/js/includes/flash.js"></script>
<?php endif; ?>
<?php if (isset($_GET['error']) && !empty($_GET['error'])): ?>
    <div id="toast-error"
         style="
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%) scale(0.95);
            background-color: #dc2626 !important; /* Rosso */
            color: white !important;
            padding: 12px 24px;
            border-radius: 9999px;
            font-weight: 600;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s ease-out;
            text-align: center;
            font-size: 14px;
         ">
        <?= htmlspecialchars($_GET['error']) ?>
    </div>

    
<?php endif; ?>
<?php if (isset($_GET['info']) && !empty($_GET['info'])): ?>
    <div id="toast-info"
         style="
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%) scale(0.95);
            background-color: #2563eb !important; /* Blu */
            color: white !important;
            padding: 12px 24px;
            border-radius: 9999px;
            font-weight: 600;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s ease-out;
            text-align: center;
            font-size: 14px;
         ">
        <?= htmlspecialchars($_GET['info']) ?>
    </div>

    
<?php endif; ?>

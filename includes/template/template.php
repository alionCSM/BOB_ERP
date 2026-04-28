<?php
require_once '../../includes/middleware.php';
$_tplBase = rtrim($_ENV['APP_URL'] ?? '', '/');
?>
<!DOCTYPE html>
<html lang="en" class="light">
<!-- BEGIN: Head -->
<head>
    <meta charset="utf-8">
    <link href="<?= $_tplBase ?>/includes/template/dist/images/logo.png" rel="shortcut icon">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title><?php echo 'BOB - ' . htmlspecialchars($pageTitle ?? ''); ?></title>
    <!-- BEGIN: CSS Assets-->
    <link rel="stylesheet" href="<?= $_tplBase ?>/includes/template/dist/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <!-- END: CSS Assets-->
</head>
<!-- END: Head -->
<body class="py-5">
<?php include_once __DIR__ . '/../flash.php'; ?>

<!-- BEGIN: Mobile Menu -->
<?php include "mobile_menu.php"; ?>
<!-- END: Mobile Menu -->

<div class="flex mt-[4.7rem] md:mt-0">
    <!-- BEGIN: Side Menu  -->
    <?php include "menu.php"; ?>
    <!-- END: Side Menu -->

    <!-- BEGIN: Content -->
    <div class="content">
        <!-- BEGIN: Top Bar -->

        <?php include "top_bar.php"; ?>

        <!-- END: Top Bar -->


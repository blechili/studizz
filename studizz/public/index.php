<?php
/**
 * STUDIZ — Master Page Assembler (index.php)
 * -------------------------------------------
 * This file ONLY assembles HTML sections via PHP includes.
 * Zero backend logic lives here. All dynamic PHP stays in
 * /backend/gateways/ exactly as before.
 *
 * SECTION FILES (all in /public/sections/):
 *   head.php        — <head>, fonts, CSS links
 *   modals.php      — onboarding + folder modals
 *   navbar.php      — top navigation bar
 *   page-home.php   — home page (hero, mission, stats, goals)
 *   page-features.php — features grid page
 *   page-study.php  — study/upload page
 *   page-store.php  — summaries store page
 *   page-chatbot.php — AI chatbot page
 *   footer.php      — global footer + contact
 *   scripts.php     — closing scripts + JS link
 */
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/sections/head.php'; ?>

<body>

<?php include __DIR__ . '/sections/modals.php'; ?>
<?php include __DIR__ . '/sections/navbar.php'; ?>

<main id="app">
  <?php include __DIR__ . '/sections/page-home.php'; ?>
  <?php include __DIR__ . '/sections/page-features.php'; ?>
  <?php include __DIR__ . '/sections/page-study.php'; ?>
  <?php include __DIR__ . '/sections/page-store.php'; ?>
  <?php include __DIR__ . '/sections/page-chatbot.php'; ?>
</main>

<?php include __DIR__ . '/sections/footer.php'; ?>
<?php include __DIR__ . '/sections/scripts.php'; ?>

</body>
</html>

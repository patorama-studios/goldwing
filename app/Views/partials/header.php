<?php
use App\Services\SettingsService;

$appName = SettingsService::getGlobal('site.name', 'Australian Goldwing Association');
$title = $pageTitle ?? $appName;
$faviconUrl = SettingsService::getGlobal('site.favicon_url', '');
$googleMapsApiKey = trim((string) getenv('GOOGLE_MAPS_API_KEY'));
$loadGoogleMaps = $googleMapsApiKey !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@400;500;700;800&family=Noto+Sans:wght@400;500;700&family=Rajdhani:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/styles.css">
  <script src="/assets/navigation.js" defer></script>
  <?php if ($loadGoogleMaps): ?>
    <script src="/assets/js/address-autocomplete.js" defer></script>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= e($googleMapsApiKey) ?>&libraries=places&callback=goldwingAddressAutocompleteInit"></script>
  <?php endif; ?>
</head>
<body>

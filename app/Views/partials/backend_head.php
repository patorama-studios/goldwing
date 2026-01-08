<?php
use App\Services\SettingsService;

$appName = SettingsService::getGlobal('site.name', 'Goldwing Association');
$title = $pageTitle ?? $appName;
$faviconUrl = SettingsService::getGlobal('site.favicon_url', '');
$googleMapsApiKey = trim((string) getenv('GOOGLE_MAPS_API_KEY'));
$loadGoogleMaps = $googleMapsApiKey !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?></title>
  <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            primary: '#F2C94C',
            secondary: '#2F7D32',
            'background-light': '#F7F7F4',
            'background-dark': '#111827',
            'card-light': '#FFFFFF',
            'card-dark': '#1F2937',
            ink: 'rgb(var(--ink) / <alpha-value>)',
            sand: 'rgb(var(--sand) / <alpha-value>)',
            paper: 'rgb(var(--paper) / <alpha-value>)',
            line: 'rgb(var(--line) / <alpha-value>)',
            'primary-strong': 'rgb(var(--gold-deep) / <alpha-value>)',
            ocean: 'rgb(var(--ocean) / <alpha-value>)',
            ember: 'rgb(var(--ember) / <alpha-value>)'
          },
          fontFamily: {
            display: ['Playfair Display', 'serif'],
            sans: ['Inter', 'sans-serif']
          },
          borderRadius: {
            DEFAULT: '0.75rem',
            xl: '1.25rem',
            '2xl': '1.5rem'
          },
          boxShadow: {
            soft: '0 12px 30px rgba(17, 24, 39, 0.08)',
            card: '0 20px 45px rgba(17, 24, 39, 0.12)'
          }
        }
      }
    };
  </script>
  <style>
    :root {
      --ink: 17 24 39;
      --sand: 247 247 244;
      --paper: 255 255 255;
      --line: 229 231 235;
      --gold-deep: 207 160 50;
      --ocean: 47 125 50;
      --ember: 196 114 59;
    }

    .bg-atmosphere {
      background:
        radial-gradient(1200px 600px at 85% -15%, rgba(242, 201, 76, 0.2), transparent 60%),
        radial-gradient(900px 500px at -10% 15%, rgba(47, 125, 50, 0.16), transparent 65%),
        rgb(var(--sand));
    }

    @keyframes fade-up {
      from {
        opacity: 0;
        transform: translateY(12px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes float-in {
      from {
        opacity: 0;
        transform: translateY(18px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .animate-fade-up {
      animation: fade-up 0.7s ease-out both;
    }

    .animate-float-in {
      animation: float-in 0.7s ease-out both;
    }

    .stagger-1 {
      animation-delay: 0.08s;
    }

    .stagger-2 {
      animation-delay: 0.16s;
    }

    .stagger-3 {
      animation-delay: 0.24s;
    }

    .stagger-4 {
      animation-delay: 0.32s;
    }
  </style>
</head>
<body class="bg-background-light text-gray-800 font-sans transition-colors duration-300">

<?php if ($loadGoogleMaps): ?>
  <script src="/assets/js/address-autocomplete.js" defer></script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= e($googleMapsApiKey) ?>&libraries=places&callback=goldwingAddressAutocompleteInit"></script>
<?php endif; ?>

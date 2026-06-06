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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?></title>
  <?php if ($faviconUrl): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
  <link href="/assets/vendor/quill/quill.snow.css" rel="stylesheet">
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

    /* Quill rich-text editor — match the surrounding form controls. */
    .gw-wysiwyg { position: relative; }
    .gw-wysiwyg .ql-toolbar.ql-snow {
      border: 1px solid #e5e7eb;
      border-bottom: 0;
      border-radius: 0.5rem 0.5rem 0 0;
      background: #f9fafb;
    }
    .gw-wysiwyg .ql-container.ql-snow {
      border: 1px solid #e5e7eb;
      border-radius: 0 0 0.5rem 0.5rem;
      background: #fff;
      font-family: inherit;
      font-size: 0.875rem;
      min-height: 160px;
    }
    .gw-wysiwyg .ql-editor { min-height: 160px; }
    .gw-wysiwyg .ql-editor:focus { outline: none; }
    .gw-wysiwyg .gw-emoji-btn {
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 1.05rem; line-height: 1;
    }
    .gw-emoji-popover {
      position: absolute; z-index: 50;
      box-shadow: 0 12px 30px rgba(17, 24, 39, 0.18);
      border-radius: 0.75rem; overflow: hidden;
    }
    .gw-emoji-popover[hidden] { display: none; }
  </style>
</head>
<body class="bg-background-light text-gray-800 font-sans transition-colors duration-300">

<script src="/assets/vendor/quill/quill.js" defer></script>
<script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@1/index.js"></script>
<script src="/assets/js/goldwing-wysiwyg.js" defer></script>
<script src="/assets/js/password-strength.js" defer></script>
<?php if ($loadGoogleMaps): ?>
  <script src="/assets/js/address-autocomplete.js" defer></script>
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= e($googleMapsApiKey) ?>&libraries=places&callback=goldwingAddressAutocompleteInit"></script>
<?php endif; ?>

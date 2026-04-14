<?php
$sponsors = [
    [
        'name' => 'Honda Motorcycles Australia',
        'url' => 'https://motorcycles.honda.com.au/Touring',
        'image' => '/assets/img/sponsors/honda.png',
        'alt' => 'Honda Logo'
    ],
    [
        'name' => 'Hawkesbury Motorcycles',
        'url' => 'http://www.hawkesburymotorcycles.com.au/',
        'image' => '/assets/img/sponsors/hawkesbury.png',
        'alt' => 'Hawkesbury Motorcycles'
    ],
    [
        'name' => 'Scott Strike Conversions',
        'url' => 'http://www.scottstrikeconversions.com.au/',
        'image' => '/assets/img/sponsors/scotts.jpg',
        'alt' => 'Scott Strike Conversions'
    ]
];
?>
<section class="sponsors-section page-section" style="background: linear-gradient(180deg, #121212 0%, #0a0a0a 100%); padding: 4rem 1rem; border-top: 1px solid rgba(212, 175, 55, 0.1);">
    <div class="container" style="text-align: center;">
        <h2 style="color: #d4af37; font-size: 2rem; margin-bottom: 0.5rem; text-shadow: 0 2px 10px rgba(0,0,0,0.5);">Our Valued Sponsors</h2>
        <p style="color: #aaa; margin-bottom: 3rem; font-size: 1.1rem;">Supporting the Australian Goldwing Association and our members.</p>
        
        <div style="display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 4rem;">
            <?php foreach ($sponsors as $sponsor): ?>
                <a href="<?= e($sponsor['url']) ?>" target="_blank" rel="noopener noreferrer" style="display: block; transition: transform 0.3s ease, filter 0.3s ease; filter: grayscale(100%) opacity(0.8);">
                    <img src="<?= e($sponsor['image']) ?>" alt="<?= e($sponsor['alt']) ?>" style="max-height: 80px; max-width: 250px; width: auto; object-fit: contain;">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
.sponsors-section a:hover {
    transform: scale(1.05);
    filter: grayscale(0%) opacity(1) drop-shadow(0 0 15px rgba(212, 175, 55, 0.2)) !important;
}
</style>

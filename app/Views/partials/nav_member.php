<?php
$user = $user ?? current_user();
$items = [
    ['label' => 'Dashboard', 'href' => '/member/index.php'],
    ['label' => 'Wings', 'href' => '/member/index.php?page=wings'],
    ['label' => 'Store', 'href' => '/member/index.php?page=store'],
    ['label' => 'Notice Board', 'href' => '/member/index.php?page=notices-view'],
    ['label' => 'Fallen Wings', 'href' => '/member/index.php?page=fallen-wings'],
    ['label' => 'Profile', 'href' => '/member/index.php?page=profile'],
];
if (function_exists('can_access_path')) {
    $items = array_values(array_filter($items, function ($item) use ($user) {
        return can_access_path($user, $item['href']);
    }));
}
?>
<nav class="navbar">
  <div class="container nav-links">
    <div class="brand">Australian Goldwing Association</div>
    <?php foreach ($items as $item): ?>
      <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
    <?php endforeach; ?>
    <a class="cta" href="/logout.php">Logout</a>
  </div>
</nav>

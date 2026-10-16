<?php
// Seeded with Liceo's founding year so the books look the same on every load
mt_srand(1955);
$bookColors = ['#7b1113', '#a3262a', '#5a0c0e', '#f2b705', '#c98b2b', '#2f5d50',
               '#1e3a5f', '#8b5e34', '#e8dcc6', '#4a2a1a', '#5b6b3a', '#3b2f4a'];
$books = [];
for ($i = 0; $i < 52; $i++) {
    $extra = '';
    if ($i % 17 === 9)      { $extra = ' lean'; }
    elseif ($i % 11 === 5)  { $extra = ' short-gap'; }

    $books[] = [
        'h'     => mt_rand(78, 140),
        'w'     => mt_rand(14, 30),
        'c'     => $bookColors[mt_rand(0, count($bookColors) - 1)],
        'class' => 's' . mt_rand(1, 4) . $extra,
    ];
}
?>
<div class="shelf" aria-hidden="true">
    <?php foreach ($books as $b): ?>
        <span class="book <?= $b['class'] ?>"
              style="--h: <?= $b['h'] ?>px; --w: <?= $b['w'] ?>px; --c: <?= $b['c'] ?>;"></span>
    <?php endforeach; ?>
</div>
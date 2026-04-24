<?php
$base = current_url();
$params = $_GET;
?>
<nav>
    <ul class="pagination pagination-sm mb-0" style="gap:4px">
        <?php if ($page > 1): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $base ?>?<?= http_build_query(array_merge($params, ['page' => $page - 1])) ?>"
               style="background:#1E1E1E;border-color:#333;color:#aaa">&laquo;</a>
        </li>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($lastPage, $page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base ?>?<?= http_build_query(array_merge($params, ['page' => $i])) ?>"
               style="<?= $i === $page ? 'background:#D94032;border-color:#D94032;color:#fff' : 'background:#1E1E1E;border-color:#333;color:#aaa' ?>">
                <?= $i ?>
            </a>
        </li>
        <?php endfor; ?>

        <?php if ($page < $lastPage): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $base ?>?<?= http_build_query(array_merge($params, ['page' => $page + 1])) ?>"
               style="background:#1E1E1E;border-color:#333;color:#aaa">&raquo;</a>
        </li>
        <?php endif; ?>
    </ul>
</nav>

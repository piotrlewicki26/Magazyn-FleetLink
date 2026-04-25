<?php
if (!defined('IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
?>
</div><!-- /.container-fluid -->
<footer class="bg-light border-top mt-5 py-3">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col text-muted small">
                &copy; <?= date('Y') ?> FleetLink System GPS v<?= defined('APP_VERSION') ? APP_VERSION : '1.0.0' ?>
                &mdash; System zarządzania urządzeniami GPS
            </div>
            <div class="col-auto text-muted small">
                <a href="https://www.fleetlink.pl" target="_blank" rel="noopener" class="text-muted text-decoration-none me-3">
                    <i class="fas fa-globe me-1"></i>www.fleetlink.pl
                </a>
                <?php if (isAdmin()): ?>
                <a href="<?= getBaseUrl() ?>settings.php" class="text-muted text-decoration-none">
                    <i class="fas fa-cog me-1"></i>Ustawienia
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script src="<?= getBaseUrl() ?>assets/js/app.js"></script>
</body>
</html>

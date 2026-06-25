<?php if (!empty($user) && in_array($user['role'], [ROLE_LANDLORD, ROLE_TENANT])): ?>
    </div> <!-- close .page-shell -->
    </main>
    </div> <!-- close .page-wrapper -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var overlay = document.getElementById('sidebarOverlay');
            if (overlay) {
                overlay.addEventListener('click', function () {
                    document.body.classList.remove('sidebar-open');
                });
            }
        });
    </script>
<?php else: ?>
    </main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(rtrim($config['app']['base_url'], '/')) ?>/assets/js/dlp.js"></script>
</body>
</html>

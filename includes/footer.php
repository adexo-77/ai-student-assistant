<?php
// ============================================================
//  FOOTER.PHP – closes the app shell (sidebar/topbar layout),
//  adds the mobile sidebar toggle and loads Bootstrap's JS.
// ============================================================

// The shell is only opened by header.php on logged-in pages
// (login / register render centred without the sidebar).
if (empty($hideNavbar) && isLoggedIn()) {
?>
</main><!-- /.app-content -->
</div><!-- /.app-main -->
</div><!-- /.app-shell -->

<script>
// Mobile sidebar toggle (vanilla JS, no extra libraries).
(function () {
    var sidebar  = document.getElementById('appSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggle   = document.getElementById('sidebarToggle');
    if (!sidebar || !backdrop || !toggle) { return; }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-visible');
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-open');
        backdrop.classList.toggle('is-visible');
    });

    backdrop.addEventListener('click', closeSidebar);

    // Close the sidebar after navigating on a small screen.
    sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a') && window.innerWidth < 992) {
            closeSidebar();
        }
    });
})();
</script>
<?php } ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
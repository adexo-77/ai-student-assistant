<?php
// ============================================================
//  HEADER.PHP â€“ <head>, Bootstrap 5 and the APP SHELL:
//  a professional SIDEBAR + TOPBAR shared by every page.
//
//  Include this at the top of every page like this (AFTER
//  including includes/auth.php):
//
//      $pageTitle = 'Dashboard';
//      $navActive = 'dashboard';
//      include __DIR__ . '/includes/header.php';
//
//  Set  $hideNavbar = true  on the login / register pages.
// ============================================================

require_once __DIR__ . '/../config.php';   // for the BOT_NAME constant
require_once __DIR__ . '/auth.php';

if (!isset($pageTitle)) {
    $pageTitle = 'AI Chat';
}
$navActive = $navActive ?? '';

// Pending-assignment count for the notification bell (real data,
// tiny query, silently 0 when the database is unavailable).
$pendingCount = 0;
if (empty($hideNavbar) && isLoggedIn()) {
    try {
        $pc = getPDO()->prepare(
            "SELECT COUNT(*) AS c FROM assignments WHERE user_id = ? AND status <> 'completed'"
        );
        $pc->execute([(int)$_SESSION['user_id']]);
        $pendingCount = (int)$pc->fetch()['c'];
    } catch (Exception $e) {
        $pendingCount = 0;
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- Inter font (falls back gracefully when offline) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Professional UI (2026-09): Inter is the single app font. display=swap
         keeps text visible while the font loads; system fonts cover offline. -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 (loaded from the internet CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Tailwind CSS (layered alongside Bootstrap for utility-first enhancements) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        // Preflight is switched OFF on purpose: Bootstrap 5 already
        // provides the base/reset layer and Tailwind's reset would
        // fight it.  Tailwind therefore acts as a PURE utility layer
        // that any page can use next to Bootstrap, with no side
        // effects on the pages that have not been converted yet.
        corePlugins: { preflight: false },
        theme: {
          extend: {
            colors: {
              /* the original scale keeps working â€¦ */
              navy: {
                900: '#0b1426',
                800: '#101c31',
                700: '#14233c',
                600: '#1b2f4e',
              },
              accent: {
                purple: '#7b5bf5',
                blue: '#4d8bff',
              },
              /* â€¦ and the design-system brand scales are added. */
              brand: {
                50:  '#eef2ff',
                100: '#e0e7ff',
                200: '#c7d2fe',
                300: '#a5b4fc',
                400: '#818cf8',
                500: '#6366f1',
                600: '#4f46e5',
                700: '#4338ca',
                800: '#3730a3',
                900: '#312e81',
              },
              surface: {
                900: '#0a0f1c',
                800: '#0d1526',
                700: '#111a2e',
                600: '#16203a',
                500: '#1e2a45',
              },
            },
            fontFamily: {
              sans: ['Inter', 'ui-sans-serif', 'Segoe UI', 'system-ui', '-apple-system', 'Helvetica Neue', 'Arial', 'sans-serif'],
            },
            borderRadius: {
              xl: '0.9rem',
              '2xl': '1.15rem',
              '3xl': '1.5rem',
            },
            boxShadow: {
              card: '0 1px 2px 0 rgba(2, 6, 23, 0.45)',
              lift: '0 12px 30px -14px rgba(2, 6, 23, 0.85)',
            },
          },
        },
      };
    </script>

    <!-- Our own theme / styles -->
    <link href="css/style.css" rel="stylesheet">
</head>
<body>

<?php $me = ''; ?>

<?php if (empty($hideNavbar) && isLoggedIn()) { ?>
<?php $me = (string)($_SESSION['username'] ?? 'student'); ?>
<div class="app-shell">
            <!-- Professional AI Student mark (2026-09): pure inline SVG + CSS,
                 no new assets, no extra requests. Mortarboard + spark. -->
<!-- ================= SIDEBAR ================= -->
<aside class="app-sidebar" id="appSidebar">
    <a class="sidebar-brand" href="dashboard.php">
        <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><rect x="1.5" y="1.5" width="29" height="29" rx="8" fill="url(#brandMarkG)"/><path d="M16 8.2 6.8 12.2 16 16.2 25.2 12.2 16 8.2z" fill="#fff"/><path d="M10.4 14.6v3.1c0 1.1 2.5 2.5 5.6 2.5s5.6-1.4 5.6-2.5v-3.1l-5.6 2.4-5.6-2.4z" fill="#c7d2fe"/><path d="M22.9 13.4v3.9" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><circle cx="22.9" cy="18.4" r="1.2" fill="#fff"/><path d="M13.2 22.6l1 1 2-2.2" stroke="#fff" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><defs><linearGradient id="brandMarkG" x1="0" y1="0" x2="32" y2="32"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs></svg>
        <span class="brand-text">
            <span class="brand-name"><?php echo htmlspecialchars(BOT_NAME); ?></span>
            <span class="brand-sub">AI Student Assistant</span>
        </span>
    </a>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <a class="side-link<?php echo ($navActive === 'dashboard' ? ' active' : ''); ?>" href="dashboard.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z"/></svg>
            <span>Dashboard</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'chat' ? ' active' : ''); ?>" href="index.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 5h16v11H9l-5 4V5z"/></svg>
            <span>AI Assistant</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'courses' ? ' active' : ''); ?>" href="courses.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 5h16v14H4zM4 9h16M8 5v4"/></svg>
            <span>My Courses</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'assignments' ? ' active' : ''); ?>" href="assignments.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h9l3 3v15H6zM9 9h6M9 12.5h6M9 16h4"/></svg>
            <span>Assignments</span>
            <?php if ($pendingCount > 0) { ?><span class="side-badge"><?php echo $pendingCount; ?></span><?php } ?>
        </a>
        <a class="side-link<?php echo ($navActive === 'quizzes' ? ' active' : ''); ?>" href="quizzes.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg>
            <span>Quizzes</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'materials' ? ' active' : ''); ?>" href="materials.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg>
            <span>Study Materials</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'history' ? ' active' : ''); ?>" href="history.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>History</span>
        </a>
        <a class="side-link<?php echo ($navActive === 'profile' ? ' active' : ''); ?>" href="profile.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8zM4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>
            <span>Profile</span>
        </a>
    </nav>

    <a class="side-link side-logout" href="logout.php">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M14 4h-8v16h8M10 12h10m-3-3 3 3-3 3"/></svg>
        <span>Logout</span>
    </a>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-main">
    <header class="app-topbar">
        <button class="topbar-burger" id="sidebarToggle" type="button" aria-label="Toggle menu">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <div class="topbar-left">
            <h1 class="topbar-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
            <?php if (isset($course) && is_array($course) && isset($course['name'])) { ?>
            <span class="topbar-context badge"><?php echo htmlspecialchars((string)$course['name']); ?></span>
            <?php } ?>
        </div>

        <form class="topbar-search d-none d-md-flex" action="search.php" method="get" role="search">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="search-icon"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M10.5 4a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13zM15.5 15.5 21 21"/></svg>
            <input type="search" name="q" placeholder="Search courses, assignments, quizzes&hellip;" aria-label="Search" class="topbar-search-input">
        </form>

        <div class="topbar-right">
            <a class="topbar-bell" href="assignments.php" title="Pending assignments">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 3a6 6 0 0 1 6 6v4l2 3H4l2-3V9a6 6 0 0 1 6-6zM10 19a2 2 0 0 0 4 0"/></svg>
                <?php if ($pendingCount > 0) { ?><span class="bell-badge"><?php echo $pendingCount; ?></span><?php } ?>
            </a>

            <div class="dropdown">
                <button class="topbar-user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="topbar-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($me, 0, 1))); ?></span>
                    <span class="topbar-name d-none d-sm-inline"><?php echo htmlspecialchars($me); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Signed in as <?php echo htmlspecialchars($me); ?></h6></li>
                    <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                    <li><a class="dropdown-item" href="history.php">History</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </header>
    <main class="app-content">
<?php } ?>

<?php /* ============================================================
   FIL-RISE MOBILE SIDEBAR TOGGLE
   Include this RIGHT BEFORE </body> on every staff page
============================================================ */ ?>

<!-- MOBILE HEADER: logo on left, hamburger on right -->
<div class="mobile-header">
  <img src="7.png" alt="Fil-Rise Logo">
  <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()">
    <span></span>
    <span></span>
    <span></span>
  </button>
</div>

<!-- OVERLAY (tap to close sidebar) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<script>
function toggleSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const btn     = document.getElementById('hamburgerBtn');
  const overlay = document.getElementById('sidebarOverlay');

  if (sidebar.classList.contains('open')) {
    closeSidebar();
  } else {
    sidebar.classList.add('open');
    btn.classList.add('open');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

function closeSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const btn     = document.getElementById('hamburgerBtn');
  const overlay = document.getElementById('sidebarOverlay');

  sidebar.classList.remove('open');
  btn.classList.remove('open');
  overlay.classList.remove('active');
  document.body.style.overflow = '';
}

// Auto-close sidebar when a nav link is clicked on mobile
document.querySelectorAll('.sidebar a').forEach(link => {
  link.addEventListener('click', function() {
    if (window.innerWidth <= 992) closeSidebar();
  });
});

// Auto-close on resize to desktop and reset layout
window.addEventListener('resize', function() {
  if (window.innerWidth > 992) {
    closeSidebar();
    // Clear any inline styles set by mobile JS so CSS takes over
    const main = document.querySelector('.main');
    if (main) main.style.marginTop = '';
    document.body.style.overflow = '';
  }
});
</script>
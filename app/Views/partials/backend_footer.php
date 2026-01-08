<script>
  document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-backend-nav-toggle]');
    const sidebar = document.querySelector('[data-backend-sidebar]');
    const overlay = document.querySelector('[data-backend-nav-overlay]');
    if (!toggle || !sidebar) {
      return;
    }

    const openSidebar = () => {
      sidebar.classList.remove('-translate-x-full');
      sidebar.classList.add('translate-x-0');
      sidebar.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
      if (overlay) {
        overlay.classList.remove('hidden');
      }
      document.body.classList.add('overflow-hidden');
    };

    const closeSidebar = () => {
      sidebar.classList.add('-translate-x-full');
      sidebar.classList.remove('translate-x-0');
      sidebar.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
      if (overlay) {
        overlay.classList.add('hidden');
      }
      document.body.classList.remove('overflow-hidden');
    };

    toggle.addEventListener('click', () => {
      const isOpen = toggle.getAttribute('aria-expanded') === 'true';
      if (isOpen) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    if (overlay) {
      overlay.addEventListener('click', closeSidebar);
    }

    sidebar.addEventListener('click', (event) => {
      if (window.innerWidth >= 768) {
        return;
      }
      const target = event.target;
      if (target && target.closest('a')) {
        closeSidebar();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSidebar();
      }
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth >= 768) {
        closeSidebar();
      }
    });
  });
</script>
</body>
</html>

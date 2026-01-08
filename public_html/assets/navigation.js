document.addEventListener('DOMContentLoaded', () => {
  const nav = document.querySelector('.navbar');
  if (!nav) {
    return;
  }

  const navLinks = nav.querySelector('[data-nav-links]');
  const menuToggle = nav.querySelector('[data-nav-toggle]');
  const submenuToggles = Array.from(nav.querySelectorAll('.nav-subtoggle'));

  const closeSubmenus = (except = null) => {
    submenuToggles.forEach(button => {
      if (button === except) {
        return;
      }
      button.setAttribute('aria-expanded', 'false');
      const item = button.closest('.nav-item');
      if (item) {
        item.classList.remove('is-open');
      }
    });
  };

  if (menuToggle && navLinks) {
    menuToggle.addEventListener('click', () => {
      const isOpen = navLinks.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (!isOpen) {
        closeSubmenus();
      }
    });

    navLinks.addEventListener('click', (event) => {
      const target = event.target;
      if (target && target.closest('a') && window.innerWidth < 900) {
        navLinks.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
        closeSubmenus();
      }
    });
  }

  submenuToggles.forEach(button => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const item = button.closest('.nav-item');
      if (!item) {
        return;
      }
      const expanded = button.getAttribute('aria-expanded') === 'true';
      closeSubmenus(button);
      button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      item.classList.toggle('is-open', !expanded);
    });
  });

  document.addEventListener('click', (event) => {
    if (!nav.contains(event.target)) {
      closeSubmenus();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSubmenus();
      if (navLinks) {
        navLinks.classList.remove('is-open');
      }
      if (menuToggle) {
        menuToggle.setAttribute('aria-expanded', 'false');
      }
    }
  });

  const cartToggle = document.querySelector('[data-cart-toggle]');
  const cartDrawer = document.querySelector('[data-cart-drawer]');
  const cartCloseButtons = document.querySelectorAll('[data-cart-close]');
  const toggleCart = (open) => {
    if (!cartDrawer) return;
    const isOpen = open ?? !cartDrawer.classList.contains('is-open');
    cartDrawer.classList.toggle('is-open', isOpen);
    cartDrawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    document.body.style.overflow = isOpen ? 'hidden' : '';
  };

  if (cartToggle && cartDrawer) {
    cartToggle.addEventListener('click', () => toggleCart(true));
    cartCloseButtons.forEach((button) => {
      button.addEventListener('click', () => toggleCart(false));
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        toggleCart(false);
      }
    });
  }
});

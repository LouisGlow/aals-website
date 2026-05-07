/* ==========================================================================
   AALS — Academy of Advanced Life Support
   Main JavaScript
   ========================================================================== */

// Sticky header shadow on scroll
const hdr = document.querySelector('.site-header');
if (hdr) {
  window.addEventListener('scroll', () => {
    hdr.classList.toggle('scrolled', window.scrollY > 8);
  }, { passive: true });
}

// Hamburger menu
const burger = document.getElementById('burger');
const mobNav = document.getElementById('mob-nav');

if (burger && mobNav) {
  burger.addEventListener('click', () => {
    const open = burger.classList.toggle('open');
    mobNav.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', open);
  });

  // Close mobile nav when any link is clicked
  mobNav.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      burger.classList.remove('open');
      mobNav.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
    });
  });

  // Close mobile nav on outside click
  document.addEventListener('click', (e) => {
    if (!hdr.contains(e.target)) {
      burger.classList.remove('open');
      mobNav.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
    }
  });
}

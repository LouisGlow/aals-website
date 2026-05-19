// Mobile nav toggle
const burger = document.getElementById('burger');
const mobNav = document.getElementById('mob-nav');
if (burger && mobNav) {
  burger.addEventListener('click', () => {
    const open = mobNav.classList.toggle('open');
    burger.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', String(open));
  });
  // Close mobile nav on link click
  mobNav.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      mobNav.classList.remove('open');
      burger.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
    });
  });
}

// Active nav link from pathname
(function () {
  const path = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mob-nav a').forEach(a => {
    const href = a.getAttribute('href') || '';
    const isActive = href === path || (path === 'index.html' && href === 'index.html');
    a.classList.toggle('active', isActive);
  });
}());

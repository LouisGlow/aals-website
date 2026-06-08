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

// -------------------------------------------------------------
// REGISTRATION FORM
//   - Pre-fill course + date from query params (?course=acls&date=2025-12-01-acls)
//   - On submit: prevent default, hide form, reveal inline success state.
//   - TODO: wire to a real backend. Replace the body of `handleSubmit`
//     with a fetch() to a Cloudflare Pages Function (or Formspree/etc.)
//     that emails the form data to agrecia@resus.co.za. The success-state
//     UI swap stays the same.
// -------------------------------------------------------------
(function () {
  const form = document.getElementById('reg-form');
  if (!form) return;
  const successEl = document.getElementById('reg-success');
  const submitBtn = document.getElementById('reg-submit');

  // Pre-fill from URL query params
  const params = new URLSearchParams(location.search);
  const courseParam = params.get('course');
  const dateParam = params.get('date');
  if (courseParam) {
    const courseSelect = form.querySelector('#course');
    if (courseSelect && [...courseSelect.options].some(o => o.value === courseParam)) {
      courseSelect.value = courseParam;
    }
  }
  if (dateParam) {
    const dateSelect = form.querySelector('#course_date');
    if (dateSelect && [...dateSelect.options].some(o => o.value === dateParam)) {
      dateSelect.value = dateParam;
    }
  }

  function handleSubmit(e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    // --- BACKEND STUB ---
    // Currently no backend is wired. When a real backend is added, replace this
    // block with a fetch() call. Until then, just reveal the success state so
    // staff can preview the flow end-to-end.
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';
    }
    setTimeout(() => {
      form.hidden = true;
      if (successEl) {
        successEl.hidden = false;
        successEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }, 400);
  }

  form.addEventListener('submit', handleSubmit);
}());

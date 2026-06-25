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
// Cloudflare Pages serves both /courses and /courses.html, so we compare
// the basename with the .html stripped from both the URL and the link href.
(function () {
  const norm = s => (s || '').replace(/^\/+|\/+$/g, '').replace(/\.html$/i, '').toLowerCase() || 'index';
  const path = norm(location.pathname.split('/').pop());
  document.querySelectorAll('.nav-links a, .mob-nav a').forEach(a => {
    const isActive = norm(a.getAttribute('href')) === path;
    a.classList.toggle('active', isActive);
  });
}());

// -------------------------------------------------------------
// REGISTRATION FORM
//   - Pre-fill course + date from query params
//     (?course=acls&date=2026-07-10-acls)
//   - On submit: POST to register.php (PHP mail handler on cPanel),
//     swap the form for the inline success state, or show an error
//     and re-enable submit if the server rejects.
// -------------------------------------------------------------
(function () {
  const form = document.getElementById('reg-form');
  if (!form) return;
  const successEl = document.getElementById('reg-success');
  const submitBtn = document.getElementById('reg-submit');
  const submitLabel = submitBtn ? submitBtn.textContent : 'Submit Registration';

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

  function showError(msg) {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = submitLabel;
    }
    alert(
      "Sorry, your registration couldn't be sent right now.\n\n" +
      (msg ? msg + "\n\n" : "") +
      "Please try again, or email agrecia@resus.co.za directly with your details."
    );
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';
    }

    try {
      const res = await fetch('register.php', {
        method: 'POST',
        body: new FormData(form),
      });
      let data = null;
      try { data = await res.json(); } catch (_) { /* ignore parse errors */ }

      if (!res.ok || !data || !data.ok) {
        showError(data && data.error ? data.error : 'HTTP ' + res.status);
        return;
      }

      form.hidden = true;
      if (successEl) {
        successEl.hidden = false;
        successEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } catch (err) {
      showError(err && err.message ? err.message : 'Network error');
    }
  }

  form.addEventListener('submit', handleSubmit);
}());

// -------------------------------------------------------------
// CONTACT FORM
//   POST contact.php (Resend), swap form for success state.
// -------------------------------------------------------------
(function () {
  const form = document.getElementById('contact-form');
  if (!form) return;
  const successEl = document.getElementById('contact-success');
  const submitBtn = document.getElementById('contact-submit');
  const submitLabel = submitBtn ? submitBtn.textContent : 'Send Message';

  function showError(msg) {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = submitLabel;
    }
    alert(
      "Sorry, your message couldn't be sent right now.\n\n" +
      (msg ? msg + "\n\n" : "") +
      "Please email agrecia@resus.co.za directly with your enquiry."
    );
  }

  async function handleSubmit(e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending…';
    }

    try {
      const res = await fetch('contact.php', {
        method: 'POST',
        body: new FormData(form),
      });
      let data = null;
      try { data = await res.json(); } catch (_) { /* ignore parse errors */ }

      if (!res.ok || !data || !data.ok) {
        showError(data && data.error ? data.error : 'HTTP ' + res.status);
        return;
      }

      form.hidden = true;
      if (successEl) {
        successEl.hidden = false;
        successEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } catch (err) {
      showError(err && err.message ? err.message : 'Network error');
    }
  }

  form.addEventListener('submit', handleSubmit);
}());

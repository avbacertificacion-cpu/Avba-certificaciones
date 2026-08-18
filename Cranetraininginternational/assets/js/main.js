(function () {
  "use strict";

  /* Mobile navigation toggle */
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.getElementById("main-nav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    nav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        nav.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
      });
    });
  }

  /* Scroll-reveal: progressive enhancement only. If IntersectionObserver
     isn't available or the visitor prefers reduced motion, elements are
     left alone and simply render normally (no .reveal class is added). */
  var prefersReducedMotion = window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (!prefersReducedMotion && "IntersectionObserver" in window) {
    var revealTargets = document.querySelectorAll(
      ".card, .program-card, .stat, .two-col > div, .grid > *"
    );
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("in-view");
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
    revealTargets.forEach(function (el) {
      el.classList.add("reveal");
      io.observe(el);
    });

    /* Safety net: a fast/instant jump (scrollbar drag, End key, a browser
       or OS quirk around smooth-scroll) should never leave content stuck
       invisible. Anything not revealed within a few seconds is force-shown. */
    setTimeout(function () {
      revealTargets.forEach(function (el) { el.classList.add("in-view"); });
      io.disconnect();
    }, 4000);
  }

  /* Programs catalog filter (programs.html) */
  var filterBar = document.querySelector("[data-filter-bar]");
  if (filterBar) {
    var buttons = filterBar.querySelectorAll(".filter-btn");
    var cards = document.querySelectorAll("[data-category]");
    buttons.forEach(function (btn) {
      btn.addEventListener("click", function () {
        buttons.forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
        btn.setAttribute("aria-pressed", "true");
        var target = btn.getAttribute("data-filter");
        cards.forEach(function (card) {
          var match = target === "all" || card.getAttribute("data-category") === target;
          card.style.display = match ? "" : "none";
        });
      });
    });
  }

  /* Contact form validation + submission (contact.html) */
  var form = document.getElementById("contact-form");
  if (form) {
    var statusBox = document.getElementById("form-status");

    var showStatus = function (type, message) {
      statusBox.textContent = message;
      statusBox.className = "form-status show " + type;
      statusBox.setAttribute("role", "alert");
    };

    var setFieldError = function (field, message) {
      var wrap = field.closest(".form-field");
      if (!wrap) return;
      wrap.classList.add("has-error");
      var err = wrap.querySelector(".field-error");
      if (err) err.textContent = message;
    };

    var clearErrors = function () {
      form.querySelectorAll(".form-field.has-error").forEach(function (f) {
        f.classList.remove("has-error");
      });
    };

    var validate = function () {
      clearErrors();
      var valid = true;
      var required = form.querySelectorAll("[required]");
      required.forEach(function (field) {
        if (!field.value || !field.value.trim()) {
          setFieldError(field, "This field is required.");
          valid = false;
        }
      });
      var email = form.querySelector("#email");
      if (email && email.value) {
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email.value.trim())) {
          setFieldError(email, "Enter a valid email address.");
          valid = false;
        }
      }
      var participants = form.querySelector("#participants");
      if (participants && participants.value && Number(participants.value) < 1) {
        setFieldError(participants, "Enter a number of 1 or more.");
        valid = false;
      }
      return valid;
    };

    form.addEventListener("submit", function (evt) {
      evt.preventDefault();
      statusBox.className = "form-status";

      if (!validate()) {
        showStatus("err", "Please correct the highlighted fields and resubmit.");
        return;
      }

      var submitBtn = form.querySelector('[type="submit"]');
      var originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = "Sending...";

      var formData = new FormData(form);

      fetch("api/contact.php", {
        method: "POST",
        headers: { "Accept": "application/json" },
        body: formData
      })
        .then(function (response) {
          return response.json().catch(function () {
            throw new Error("bad_response");
          }).then(function (data) {
            return { ok: response.ok, data: data };
          });
        })
        .then(function (result) {
          if (result.ok && result.data && result.data.success) {
            form.reset();
            showStatus("ok", "Thank you. Your request has been sent — we will follow up shortly.");
          } else {
            var msg = (result.data && result.data.message) ||
              "We could not send your request right now. Please email us directly at info@cranetraininginternational.com and we will respond as soon as possible.";
            showStatus("err", msg);
          }
        })
        .catch(function () {
          showStatus("err", "We could not send your request right now. Please email us directly at info@cranetraininginternational.com and we will respond as soon as possible.");
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }
})();

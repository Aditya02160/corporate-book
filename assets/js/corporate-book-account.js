/**
 * Corporate Book accounts: sign in, sign up and Razorpay Checkout inside the book.
 * Passwords, prices and payment signatures are all checked on the server; this file only sends the forms.
 */
(function () {
  'use strict';
  var cfg = window.corporateBookAccount;
  if (!cfg) {
    return;
  }

  function post(data) {
    var form = new FormData();
    data._ajax_nonce = cfg.nonce;
    Object.keys(data).forEach(function (key) {
      form.append(key, data[key]);
    });
    return fetch(cfg.url, { method: 'POST', body: form, credentials: 'same-origin' })
      .then(function (response) {
        return response.json();
      })
      .catch(function () {
        throw new Error(cfg.failed);
      })
      .then(function (answer) {
        if (!answer || answer.success !== true) {
          throw new Error(answer && typeof answer.data === 'string' ? answer.data : cfg.failed);
        }
        return answer.data || {};
      });
  }

  function say(box, text) {
    var message = box.querySelector('.cb-account-message');
    if (message) {
      message.textContent = text || '';
    }
  }

  // Runs one request for a box: reloads the page when it works, shows the reason when it does not.
  function run(box, control, request) {
    control.disabled = true;
    say(box, '');
    request().then(function (data) {
      if (data && data.otp) {
        // Sign up step 2: show the code box; the next submit sends the code.
        var code = box.querySelector('.cb-otp');
        if (code) {
          code.hidden = false;
          code.disabled = false;
          code.required = true;
          code.focus();
          control.textContent = cfg.verify || control.textContent;
        }
        control.disabled = false;
        say(box, data.message);
      } else if (data && data.reload) {
        say(box, data.message);
        setTimeout(function () {
          window.location.reload();
        }, 2500);
      } else if (data && data.message) {
        control.disabled = false;
        say(box, data.message);
      } else if (data && data.go) {
        window.location.href = data.go;
      } else {
        window.location.reload();
      }
    }, function (error) {
      control.disabled = false;
      say(box, error.message);
    });
  }

  function loadCheckout() {
    if (window.Razorpay) {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = 'https://checkout.razorpay.com/v1/checkout.js';
      script.onload = resolve;
      script.onerror = function () {
        reject(new Error(cfg.failed));
      };
      document.head.appendChild(script);
    });
  }

  // Opens Razorpay Checkout for an order, then has the server verify the payment.
  function pay(order) {
    return loadCheckout().then(function () {
      return new Promise(function (resolve, reject) {
        new window.Razorpay({
          key: order.key,
          amount: order.amount,
          currency: order.currency,
          name: order.name,
          description: order.description,
          order_id: order.order_id,
          subscription_id: order.subscription_id,
          prefill: order.prefill,
          handler: resolve,
          modal: {
            ondismiss: function () {
              reject(new Error(cfg.cancelled));
            }
          }
        }).open();
      });
    }).then(function (paid) {
      return post({
        action: 'corporate_book_verify',
        order_id: paid.razorpay_order_id || '',
        subscription_id: paid.razorpay_subscription_id || '',
        payment_id: paid.razorpay_payment_id,
        signature: paid.razorpay_signature
      });
    });
  }

  // "Continue with Google" buttons, once Google's script has loaded.
  function google() {
    var spots = document.querySelectorAll('.cb-google:not([data-ready])');
    if (!spots.length) {
      return;
    }
    if (!window.google || !window.google.accounts) {
      setTimeout(google, 300);
      return;
    }
    window.google.accounts.id.initialize({
      client_id: spots[0].getAttribute('data-client'),
      callback: function (answer) {
        var box = spots[0].closest('.cb-account');
        post({ action: 'corporate_book_google', credential: answer.credential }).then(function () {
          window.location.reload();
        }, function (error) {
          say(box, error.message);
        });
      }
    });
    Array.prototype.forEach.call(spots, function (spot) {
      spot.setAttribute('data-ready', '1');
      window.google.accounts.id.renderButton(spot, { theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill' });
    });
  }
  google();

  document.addEventListener('change', function (event) {
    if (event.target.classList.contains('cb-gift-check')) {
      document.getElementById('cb-gift-email').hidden = !event.target.checked;
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var box = form.closest('.cb-account');
    if (!box || !form.getAttribute('data-action')) {
      return;
    }
    event.preventDefault();
    var data = { action: form.getAttribute('data-action') };
    Array.prototype.forEach.call(form.elements, function (field) {
      if (field.name && !field.disabled) {
        data[field.name] = field.value;
      }
    });
    run(box, form.querySelector('button[type="submit"]'), function () {
      return post(data);
    });
  });

  document.addEventListener('click', function (event) {
    var off = event.target.closest('.cb-autopay-off');
    if (off && off.closest('.cb-account')) {
      event.preventDefault();
      if (window.confirm(cfg.autopayOff)) {
        run(off.closest('.cb-account'), off, function () {
          return post({ action: 'corporate_book_autopay_cancel' });
        });
      }
      return;
    }
    var target = event.target.closest('.cb-plan, .cb-show-signup, .cb-show-signin');
    var box = target && target.closest('.cb-account');
    if (!box) {
      return;
    }
    event.preventDefault();
    if (target.classList.contains('cb-plan')) {
      run(box, target, function () {
        var gift = document.getElementById('cb-gift-email');
        var auto = box.querySelector('.cb-autopay-check') || document.querySelector('.cb-autopay-check');
        var giving = gift && !gift.hidden && gift.value;
        return post({ action: 'corporate_book_order', plan: target.getAttribute('data-plan'), gift_email: gift && !gift.hidden ? gift.value : '', autopay: auto && auto.checked && !giving ? 1 : 0 }).then(pay);
      });
      return;
    }
    var signup = target.classList.contains('cb-show-signup');
    box.querySelector('.cb-signin').hidden = signup;
    box.querySelector('.cb-signup').hidden = !signup;
    say(box, '');
  });
})();

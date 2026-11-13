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
    box.querySelector('.cb-account-message').textContent = text || '';
  }

  // Runs one request for a box: reloads the page when it works, shows the reason when it does not.
  function run(box, control, request) {
    control.disabled = true;
    say(box, '');
    request().then(function () {
      window.location.reload();
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
        order_id: paid.razorpay_order_id,
        payment_id: paid.razorpay_payment_id,
        signature: paid.razorpay_signature
      });
    });
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var signin = form.classList.contains('cb-signin');
    var box = form.closest('.cb-account');
    if (!box || !(signin || form.classList.contains('cb-signup'))) {
      return;
    }
    event.preventDefault();
    var data = { action: signin ? 'corporate_book_signin' : 'corporate_book_signup' };
    Array.prototype.forEach.call(form.elements, function (field) {
      if (field.name) {
        data[field.name] = field.value;
      }
    });
    run(box, form.querySelector('button[type="submit"]'), function () {
      return post(data);
    });
  });

  document.addEventListener('click', function (event) {
    var target = event.target.closest('.cb-plan, .cb-show-signup, .cb-show-signin');
    var box = target && target.closest('.cb-account');
    if (!box) {
      return;
    }
    event.preventDefault();
    if (target.classList.contains('cb-plan')) {
      run(box, target, function () {
        return post({ action: 'corporate_book_order', plan: target.getAttribute('data-plan') }).then(pay);
      });
      return;
    }
    var signup = target.classList.contains('cb-show-signup');
    box.querySelector('.cb-signin').hidden = signup;
    box.querySelector('.cb-signup').hidden = !signup;
    say(box, '');
  });
})();

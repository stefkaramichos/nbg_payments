{if $runtime.controller == 'checkout' && !$order_info.ds_transaction_id && $runtime.mode == 'complete'  && $order_info.payment_method.payment_id == $addons.ds_ethniki.payment_id}
{fn_ds_ethniki_change_order_status_ds($order_info.order_id, 'N')}
<div class="ethiniki-payment-session">
    {literal}<style id="antiClickjack">body{display:none !important;}</style>{/literal}
    <script>
      if (self === top) {
        document.getElementById("antiClickjack").remove();
      } else {
        top.location = self.location;
      }
    </script>

    <div class="modal">
      <img style="margin:auto;width:100px;display: block; position: relative;bottom: 15px;" src="{fn_url()}images/ds_ethniki/nbg-logo-png-002.jpg">
      
      <p class="ds_ethniki_total_amout_text">Ποσό Παραγγελίας</p>
      <p class="ds_ethniki_total_amout">{$order_info.total} €</p>

      <!-- Inputs -->
      <div class="input-group">
        <input type="text" id="card-number" class="input-field" placeholder="Αριθμός Κάρτας" readonly>
      </div>

      <div class="input-group-row">
        <div class="input-group half">
          <label class="input-label">Ημερομηνία λήξης</label>
          <input type="text" id="expiry-month" class="input-field" placeholder="Μήνας" readonly>
        </div>
        <div class="input-group half">
          <label class="input-label">&nbsp;</label>
          <input type="text" id="expiry-year" class="input-field" placeholder="Έτος" readonly>
        </div>
      </div>

      <div class="input-group">
        <label class="input-label">Κωδ. Επαλήθευσης</label>
        <input type="text" id="security-code" class="input-field" placeholder="CVV" readonly>
      </div>

      <div class="input-group">
        <label class="input-label">&nbsp;</label>
        <input type="text" id="cardholder-name" class="input-field" placeholder="Όνομα Κατόχου" readonly>
      </div>

   <!-- Message Output -->
      <div id="payment-message" style="margin:10px 0; padding:10px; border-radius:5px; display:none;"></div>

      <!-- Loading Spinner -->
    <div id="loading-spinner" style="display:none; text-align:center; margin:10px 0;">
      <img src="{fn_url()}images/ds_ethniki/Loading_icon.gif" alt="Loading..." style="width:50px;">
    </div>


      <button onclick="pay()">Πληρωμή</button>
    </div>
</div>

<!-- Hidden form -->
<form id="payment-meta" style="display:none;" method="POST" action="index.php?dispatch=3ds-callback">
  <input type="hidden" name="session_id" id="hidden-session-id">
  <input type="hidden" name="order_id" id="hidden-order-id">
  <input type="hidden" name="transaction_id" id="hidden-transaction-id">
  <input type="hidden" name="amount" id="hidden-amount">
</form>

<script>
  function displayMessage(message, isError = false) {
    const msgDiv = document.getElementById("payment-message");
    msgDiv.style.display = "block";
    msgDiv.style.color = isError ? "#a00" : "#007700";
    msgDiv.style.backgroundColor = isError ? "#fdd" : "#dfd";
    msgDiv.innerText = message;
  }

  let sessionId;

  fetch('index.php?dispatch=session.process') 
    .then(res => res.json()) 
    .then(data => {
      sessionId = data.session.id;

      PaymentSession.configure({
        session: sessionId,
        fields: {
          card: {
            number: "#card-number",
            securityCode: "#security-code",
            expiryMonth: "#expiry-month",
            expiryYear: "#expiry-year",
            nameOnCard: "#cardholder-name"
          }
        },
        frameEmbeddingMitigation: ["x-frame-options", "javascript"],
        interaction: {
          displayControl: {
            formatCard: "EMBOSSED",
            invalidFieldCharacters: "REJECT"
          }
        },
        callbacks: {
          initialized: function(response) {
            console.log("Session initialized", response);
          },
          formSessionUpdate: function(response) {
            if (response.status === "ok") {
              displayMessage("Επεξεργασία πληρωμής...");

              const orderId = "ord_{$order_info.order_id}" ;
              const transactionId = "trx_{$order_info.order_id}";
              const amount = {$order_info.total};

              document.getElementById("hidden-session-id").value = sessionId;
              document.getElementById("hidden-order-id").value = orderId;
              document.getElementById("hidden-transaction-id").value = transactionId;
              document.getElementById("hidden-amount").value = amount;

              fetch("index.php?dispatch=pay", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  sessionId,
                  orderId,
                  transactionId,
                  amount
                })
              })
              .then(res => res.json())
              .then(result => {
                if (result.status === "3ds_redirect") {
                  const form = document.createElement('form');
                  form.method = 'POST';
                  form.action = result.acs_url;

                  const input = document.createElement('input');
                  input.type = 'hidden';
                  input.name = 'creq';
                  input.value = result.creq;

                  form.appendChild(input);
                  document.body.appendChild(form);
                  form.submit();
                } else if (result.status === "auth_success" || result.status === "paid") {
                  displayMessage("✅ Πληρωμή ολοκληρώθηκε με επιτυχία!");
                  hideLoader();
                  $('.ethiniki-payment-session').css('display','none')
                } else {
                  displayMessage("❌ Αποτυχία πληρωμής.", true);
                  hideLoader();
                  console.error(result);
                }
              });
            } else {
              displayMessage("❌ Κάποια από τα στοιχεία είναι λάθος", true);
              hideLoader();
              console.error(response);
            }
          }
        }
      });
    })
    .catch(err => {
      displayMessage("❌ Κάτι πήγε λάθος παρακαλώ δοκιμάστε αργότερα", true);
      console.error(err);
    });

  function pay() {
    try {
      PaymentSession.updateSessionFromForm('card');
      showLoader();
    } catch (err) {
      hideLoader();
      displayMessage("Error submitting session: " + err.message, true);
    }
  }

  function showLoader() {
    document.getElementById("loading-spinner").style.display = "block";
  }

  function hideLoader() {
    document.getElementById("loading-spinner").style.display = "none";
  }
</script>

{/if}

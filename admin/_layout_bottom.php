</div><!-- end .main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<style>
/* ── FLOATING BUTTON ── */
#adminbotBtn {
  position: fixed; bottom: 28px; right: 28px;
  width: 58px; height: 58px; border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  box-shadow: 0 6px 20px rgba(37,99,235,.45);
  border: none; cursor: pointer; z-index: 99998;
  display: flex; align-items: center; justify-content: center;
  transition: transform .2s, box-shadow .2s;
}
#adminbotBtn:hover { transform: scale(1.08); box-shadow: 0 8px 24px rgba(37,99,235,.55); }
#adminbotBtn svg   { width: 26px; height: 26px; fill: #fff; }
#adminbotBadge {
  position: absolute; top: -3px; right: -3px;
  background: #ef4444; color: #fff; font-size: 10px; font-weight: 700;
  width: 18px; height: 18px; border-radius: 50%;
  display: none; align-items: center; justify-content: center;
  border: 2px solid #fff;
}

/* ── CHAT WINDOW ── */
#adminbotWindow {
  display: none; position: fixed;
  bottom: 96px; right: 28px;
  width: 360px; max-width: calc(100vw - 40px); height: 520px;
  background: #fff; border-radius: 20px;
  box-shadow: 0 16px 48px rgba(0,0,0,.18);
  z-index: 99999; flex-direction: column; overflow: hidden;
  animation: abPopIn .25s ease;
}
#adminbotWindow.open { display: flex; }
@keyframes abPopIn {
  from { opacity:0; transform:scale(.92) translateY(10px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}

/* Header */
.ab-header {
  background: linear-gradient(135deg, #1e40af, #2563eb);
  padding: 14px 18px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.ab-avatar {
  width: 36px; height: 36px; background: rgba(255,255,255,.2);
  border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.ab-header-info { flex: 1; }
.ab-header-name   { font-size: 14px; font-weight: 700; color: #fff; font-family:'Poppins',sans-serif; }
.ab-header-status { font-size: 11px; color: rgba(255,255,255,.75); font-family:'Poppins',sans-serif; }
.ab-close { background: none; border: none; color: rgba(255,255,255,.8); font-size: 20px; cursor: pointer; padding: 0 4px; line-height: 1; }
.ab-close:hover { color: #fff; }

/* Messages */
.ab-messages {
  flex: 1; overflow-y: auto; padding: 16px;
  display: flex; flex-direction: column; gap: 10px; background: #f8fafc;
}
.ab-messages::-webkit-scrollbar { width: 4px; }
.ab-messages::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.ab-msg {
  max-width: 82%; padding: 10px 14px; border-radius: 16px;
  font-size: 13px; line-height: 1.55; font-family:'Poppins',sans-serif; word-wrap: break-word;
}
.ab-msg.bot  { background: #fff; color: #1f2937; border-radius: 4px 16px 16px 16px; box-shadow: 0 2px 8px rgba(0,0,0,.07); align-self: flex-start; }
.ab-msg.user { background: linear-gradient(135deg,#2563eb,#1d4ed8); color: #fff; border-radius: 16px 4px 16px 16px; align-self: flex-end; }
.ab-msg.error{ background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; align-self: flex-start; }

/* Typing */
.ab-typing { display: flex; gap: 4px; align-items: center; padding: 12px 14px; }
.ab-typing span { width: 7px; height: 7px; background: #94a3b8; border-radius: 50%; animation: abdot 1.2s ease-in-out infinite; }
.ab-typing span:nth-child(2){ animation-delay: .2s; }
.ab-typing span:nth-child(3){ animation-delay: .4s; }
@keyframes abdot { 0%,80%,100%{opacity:.2;transform:scale(.8)} 40%{opacity:1;transform:scale(1)} }

/* Suggestion chips */
.ab-suggestions {
  padding: 8px 16px; display: flex; gap: 6px; flex-wrap: wrap;
  background: #f8fafc; border-top: 1px solid #f1f5f9; flex-shrink: 0;
}
.ab-chip {
  background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;
  font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 999px;
  cursor: pointer; font-family:'Poppins',sans-serif; transition: .15s; white-space: nowrap;
}
.ab-chip:hover { background: #dbeafe; }

/* Input */
.ab-input-area {
  padding: 12px 14px; border-top: 1px solid #f1f5f9;
  display: flex; gap: 8px; align-items: flex-end; background: #fff; flex-shrink: 0;
}
#adminbotInput {
  flex: 1; border: 1px solid #e5e7eb; border-radius: 12px;
  padding: 9px 13px; font-size: 13px; font-family:'Poppins',sans-serif;
  outline: none; resize: none; max-height: 80px; color: #111827; line-height: 1.4;
}
#adminbotInput:focus { border-color: #3b82f6; }
#adminbotInput::placeholder { color: #9ca3af; }
#adminbotSend {
  width: 38px; height: 38px; background: linear-gradient(135deg,#2563eb,#1d4ed8);
  border: none; border-radius: 10px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; transition: .15s; flex-shrink: 0;
}
#adminbotSend:hover    { opacity: .85; }
#adminbotSend svg      { width: 17px; height: 17px; fill: #fff; }
#adminbotSend:disabled { opacity: .4; cursor: not-allowed; }
</style>

<!-- Floating button -->
<button id="adminbotBtn" onclick="toggleAdminbot()" title="Ask AdminBot">
  <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
  <span id="adminbotBadge">1</span>
</button>

<!-- Chat window -->
<div id="adminbotWindow">
  <div class="ab-header">
    <div class="ab-avatar">🤖</div>
    <div class="ab-header-info">
      <div class="ab-header-name">AdminBot</div>
      <div class="ab-header-status">FIL-RISE Admin AI Assistant</div>
    </div>
    <button class="ab-close" onclick="toggleAdminbot()">×</button>
  </div>

  <div class="ab-messages" id="adminbotMessages"></div>

  <div class="ab-suggestions" id="adminbotSuggestions">
    <span class="ab-chip" onclick="abSendChip(this)">Sales today?</span>
    <span class="ab-chip" onclick="abSendChip(this)">Top branch?</span>
    <span class="ab-chip" onclick="abSendChip(this)">Low stock?</span>
    <span class="ab-chip" onclick="abSendChip(this)">PH trends?</span>
  </div>

  <div class="ab-input-area">
    <textarea id="adminbotInput" rows="1"
      placeholder="Ask about inventory, sales, trends..."
      onkeydown="abKeyDown(event)"
      oninput="abAutoResize(this)"></textarea>
    <button id="adminbotSend" onclick="abSendMessage()" title="Send">
      <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
    </button>
  </div>
</div>

<script>
(function(){
  let abOpen = false, abTyping = false, abHistory = [], abGreeted = false;

  const AB_CHIPS = [
    ['Sales today?',   'Top branch?',       'Low stock?',        'PH trends?'],
    ['Trending now?',  'Which to restock?',  'Top distributor?',  'Best seller?'],
    ['Branch compare?','Total inventory?',   'Falling products?', 'Rising demand?'],
  ];
  let abChipIdx = 0;

  window.toggleAdminbot = function() {
    abOpen = !abOpen;
    const win = document.getElementById('adminbotWindow');
    if (abOpen) {
      win.classList.add('open');
      document.getElementById('adminbotBadge').style.display = 'none';
      document.getElementById('adminbotInput').focus();
      if (!abGreeted) { abGreet(); abGreeted = true; }
    } else {
      win.classList.remove('open');
    }
  };

  function abGreet() {
    const h = new Date().getHours();
    const g = h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
    abAddBot(g + "! I'm AdminBot, your FIL-RISE admin assistant. I can help you with:\n\n• Sales across all branches\n• Branch performance & comparison\n• Inventory & stock levels\n• Philippine beauty & health trends\n• Business insights for your company\n\nWhat would you like to know?");
  }

  function abAddBot(text) {
    const el = document.createElement('div');
    el.className = 'ab-msg bot';
    el.textContent = text;
    abAppend(el);
    abRotateChips();
  }

  function abAddUser(text) {
    const el = document.createElement('div');
    el.className = 'ab-msg user';
    el.textContent = text;
    abAppend(el);
  }

  function abAddError(text) {
    const el = document.createElement('div');
    el.className = 'ab-msg error';
    el.textContent = text;
    abAppend(el);
  }

  function abAppend(el) {
    const msgs = document.getElementById('adminbotMessages');
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function abShowTyping() {
    const el = document.createElement('div');
    el.className = 'ab-msg bot ab-typing';
    el.id = 'abTypingEl';
    el.innerHTML = '<span></span><span></span><span></span>';
    abAppend(el);
  }

  function abRemoveTyping() {
    const el = document.getElementById('abTypingEl');
    if (el) el.remove();
  }

  function abRotateChips() {
    abChipIdx = (abChipIdx + 1) % AB_CHIPS.length;
    document.getElementById('adminbotSuggestions').innerHTML =
      AB_CHIPS[abChipIdx].map(s => `<span class="ab-chip" onclick="abSendChip(this)">${s}</span>`).join('');
  }

  window.abSendChip = function(el) {
    document.getElementById('adminbotInput').value = el.textContent;
    abSendMessage();
  };

  window.abKeyDown = function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); abSendMessage(); }
  };

  window.abAutoResize = function(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 80) + 'px';
  };

  window.abSendMessage = async function() {
    const input = document.getElementById('adminbotInput');
    const btn   = document.getElementById('adminbotSend');
    const msg   = input.value.trim();
    if (!msg || abTyping) return;

    input.value = ''; input.style.height = 'auto';
    abAddUser(msg);
    abHistory.push({ role:'user', content:msg });
    abTyping = true; btn.disabled = true;
    abShowTyping();

    try {
      const res  = await fetch('admin_chatbot_api.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ message:msg, history:abHistory.slice(-12) })
      });
      abRemoveTyping();
      const data = await res.json();
      if (data.error) { abAddError('Error: ' + data.error); }
      else {
        abAddBot(data.reply);
        abHistory.push({ role:'assistant', content:data.reply });
        if (abHistory.length > 20) abHistory = abHistory.slice(-20);
      }
    } catch(e) {
      abRemoveTyping();
      abAddError('Could not connect. Please check your internet connection.');
    }

    abTyping = false; btn.disabled = false;
    document.getElementById('adminbotInput').focus();
  };

  setTimeout(() => {
    if (!abOpen) document.getElementById('adminbotBadge').style.display = 'flex';
  }, 3000);
})();
</script>

</body>
</html>
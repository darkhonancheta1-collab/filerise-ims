<?php /* FIL-RISE Floating Chatbot Widget — include this in every page before </body> */ ?>

<style>
/* ── FLOATING BUTTON ── */
#filbotBtn {
  position: fixed;
  bottom: 28px;
  right: 28px;
  width: 58px;
  height: 58px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  box-shadow: 0 6px 20px rgba(37,99,235,.45);
  border: none;
  cursor: pointer;
  z-index: 99998;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform .2s, box-shadow .2s;
}
#filbotBtn:hover { transform: scale(1.08); box-shadow: 0 8px 24px rgba(37,99,235,.55); }
#filbotBtn svg { width: 26px; height: 26px; fill: #fff; }

/* unread badge */
#filbotBadge {
  position: absolute;
  top: -3px; right: -3px;
  background: #ef4444;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  width: 18px; height: 18px;
  border-radius: 50%;
  display: none;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
}

/* ── CHAT WINDOW ── */
#filbotWindow {
  display: none;
  position: fixed;
  bottom: 96px;
  right: 28px;
  width: 360px;
  max-width: calc(100vw - 40px);
  height: 500px;
  background: #fff;
  border-radius: 20px;
  box-shadow: 0 16px 48px rgba(0,0,0,.18);
  z-index: 99999;
  flex-direction: column;
  overflow: hidden;
  animation: popIn .25s ease;
}
#filbotWindow.open { display: flex; }
@keyframes popIn { from{opacity:0;transform:scale(.92) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }

/* header */
.fb-header {
  background: linear-gradient(135deg, #1e40af, #2563eb);
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.fb-avatar {
  width: 36px; height: 36px;
  background: rgba(255,255,255,.2);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.fb-header-info { flex: 1; }
.fb-header-name { font-size: 14px; font-weight: 700; color: #fff; font-family:'Poppins',sans-serif; }
.fb-header-status { font-size: 11px; color: rgba(255,255,255,.75); font-family:'Poppins',sans-serif; }
.fb-close {
  background: none; border: none; color: rgba(255,255,255,.8);
  font-size: 20px; cursor: pointer; padding: 0 4px; line-height: 1;
}
.fb-close:hover { color: #fff; }

/* messages */
.fb-messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: #f8fafc;
}
.fb-messages::-webkit-scrollbar { width: 4px; }
.fb-messages::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

.fb-msg {
  max-width: 82%;
  padding: 10px 14px;
  border-radius: 16px;
  font-size: 13px;
  line-height: 1.55;
  font-family: 'Poppins', sans-serif;
  word-wrap: break-word;
}
.fb-msg.bot {
  background: #fff;
  color: #1f2937;
  border-radius: 4px 16px 16px 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,.07);
  align-self: flex-start;
}
.fb-msg.user {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  color: #fff;
  border-radius: 16px 4px 16px 16px;
  align-self: flex-end;
}
.fb-msg.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; align-self: flex-start; }

/* typing indicator */
.fb-typing { display: flex; gap: 4px; align-items: center; padding: 12px 14px; }
.fb-typing span { width: 7px; height: 7px; background: #94a3b8; border-radius: 50%; animation: fbdot 1.2s ease-in-out infinite; }
.fb-typing span:nth-child(2) { animation-delay: .2s; }
.fb-typing span:nth-child(3) { animation-delay: .4s; }
@keyframes fbdot { 0%,80%,100%{opacity:.2;transform:scale(.8)} 40%{opacity:1;transform:scale(1)} }

/* quick suggestions */
.fb-suggestions {
  padding: 8px 16px;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  background: #f8fafc;
  border-top: 1px solid #f1f5f9;
  flex-shrink: 0;
}
.fb-chip {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 999px;
  cursor: pointer;
  font-family: 'Poppins', sans-serif;
  transition: .15s;
  white-space: nowrap;
}
.fb-chip:hover { background: #dbeafe; }

/* input area */
.fb-input-area {
  padding: 12px 14px;
  border-top: 1px solid #f1f5f9;
  display: flex;
  gap: 8px;
  align-items: flex-end;
  background: #fff;
  flex-shrink: 0;
}
#filbotInput {
  flex: 1;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 9px 13px;
  font-size: 13px;
  font-family: 'Poppins', sans-serif;
  outline: none;
  resize: none;
  max-height: 80px;
  color: #111827;
  line-height: 1.4;
}
#filbotInput:focus { border-color: #3b82f6; }
#filbotInput::placeholder { color: #9ca3af; }
#filbotSend {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  border: none; border-radius: 10px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: .15s; flex-shrink: 0;
}
#filbotSend:hover { opacity: .85; }
#filbotSend svg { width: 17px; height: 17px; fill: #fff; }
#filbotSend:disabled { opacity: .4; cursor: not-allowed; }
</style>

<!-- FLOATING BUTTON -->
<button id="filbotBtn" onclick="toggleFilbot()" title="Ask FilBot">
  <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
  <span id="filbotBadge">1</span>
</button>

<!-- CHAT WINDOW -->
<div id="filbotWindow">
  <div class="fb-header">
    <div class="fb-avatar">🤖</div>
    <div class="fb-header-info">
      <div class="fb-header-name">FilBot</div>
      <div class="fb-header-status">FIL-RISE AI Assistant</div>
    </div>
    <button class="fb-close" onclick="toggleFilbot()">×</button>
  </div>

  <div class="fb-messages" id="filbotMessages">
    <!-- messages injected here -->
  </div>

  <div class="fb-suggestions" id="filbotSuggestions">
    <span class="fb-chip" onclick="sendChip(this)">Low stock?</span>
    <span class="fb-chip" onclick="sendChip(this)">Top products?</span>
    <span class="fb-chip" onclick="sendChip(this)">Sales today?</span>
    <span class="fb-chip" onclick="sendChip(this)">PH trends?</span>
  </div>

  <div class="fb-input-area">
    <textarea id="filbotInput" rows="1" placeholder="Ask about inventory, sales, trends..." onkeydown="filbotKeyDown(event)" oninput="autoResize(this)"></textarea>
    <button id="filbotSend" onclick="sendMessage()" title="Send">
      <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
    </button>
  </div>
</div>

<script>
(function() {
  let isOpen   = false;
  let isTyping = false;
  let history  = [];
  let greeted  = false;

  const SUGGESTIONS = [
    ['Low stock?', 'Top products?', 'Sales today?', 'PH trends?'],
    ['Trending now?', 'Which to restock?', 'Top distributor?', 'Best seller?'],
    ['Weather effect?', 'Total inventory?', 'Falling products?', 'Rising demand?'],
  ];
  let suggIdx = 0;

  window.toggleFilbot = function() {
    isOpen = !isOpen;
    const win = document.getElementById('filbotWindow');
    if (isOpen) {
      win.classList.add('open');
      document.getElementById('filbotBadge').style.display = 'none';
      document.getElementById('filbotInput').focus();
      if (!greeted) { greet(); greeted = true; }
    } else {
      win.classList.remove('open');
    }
  };

  function greet() {
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    addBotMsg(`${greeting}! I'm FilBot, your FIL-RISE assistant. I can help you with:\n\n• Inventory & stock levels\n• Sales and product performance\n• Philippine beauty & health trends\n• Business tips for your branch\n\nWhat would you like to know?`);
  }

  function addBotMsg(text) {
    const el = document.createElement('div');
    el.className = 'fb-msg bot';
    el.textContent = text;
    appendMsg(el);
    rotateSuggestions();
  }

  function addUserMsg(text) {
    const el = document.createElement('div');
    el.className = 'fb-msg user';
    el.textContent = text;
    appendMsg(el);
  }

  function addErrorMsg(text) {
    const el = document.createElement('div');
    el.className = 'fb-msg error';
    el.textContent = text;
    appendMsg(el);
  }

  function appendMsg(el) {
    const msgs = document.getElementById('filbotMessages');
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
  }

  function showTyping() {
    const el = document.createElement('div');
    el.className = 'fb-msg bot fb-typing';
    el.id = 'filbotTyping';
    el.innerHTML = '<span></span><span></span><span></span>';
    appendMsg(el);
    return el;
  }

  function removeTyping() {
    const el = document.getElementById('filbotTyping');
    if (el) el.remove();
  }

  function rotateSuggestions() {
    suggIdx = (suggIdx + 1) % SUGGESTIONS.length;
    const container = document.getElementById('filbotSuggestions');
    container.innerHTML = SUGGESTIONS[suggIdx].map(s =>
      `<span class="fb-chip" onclick="sendChip(this)">${s}</span>`
    ).join('');
  }

  window.sendChip = function(el) {
    const text = el.textContent;
    document.getElementById('filbotInput').value = text;
    sendMessage();
  };

  window.filbotKeyDown = function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  window.autoResize = function(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 80) + 'px';
  };

  window.sendMessage = async function() {
    const input = document.getElementById('filbotInput');
    const sendBtn = document.getElementById('filbotSend');
    const msg = input.value.trim();
    if (!msg || isTyping) return;

    input.value = '';
    input.style.height = 'auto';
    addUserMsg(msg);
    history.push({ role: 'user', content: msg });

    isTyping = true;
    sendBtn.disabled = true;
    const typingEl = showTyping();

    try {
      const res = await fetch('chatbot_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: msg, history: history.slice(-12) })
      });

      removeTyping();
      const data = await res.json();

      if (data.error) {
        addErrorMsg('Error: ' + data.error);
      } else {
        addBotMsg(data.reply);
        history.push({ role: 'assistant', content: data.reply });
        // Keep history manageable
        if (history.length > 20) history = history.slice(-20);
      }

    } catch(e) {
      removeTyping();
      addErrorMsg('Could not connect. Please check your internet connection.');
    }

    isTyping = false;
    sendBtn.disabled = false;
    document.getElementById('filbotInput').focus();
  };

  // Show badge on first load after 3 seconds
  setTimeout(() => {
    if (!isOpen) {
      const badge = document.getElementById('filbotBadge');
      badge.style.display = 'flex';
    }
  }, 3000);

})();
</script>
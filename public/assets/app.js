/* QuizLive — Vue 3 (global build), sans étape de compilation. */
(() => {
  'use strict';
  const mount = document.getElementById('app');
  if (!window.Vue) {
    mount.removeAttribute('v-cloak');
    mount.textContent = 'Impossible de charger Vue.js. Vérifie la connexion Internet ou installe les bibliothèques localement (voir README).';
    return;
  }

  const { createApp, ref, computed, watch, nextTick, onMounted, onUnmounted } = window.Vue;
  const letters = ['A', 'B', 'C', 'D'];
  const getStored = (key) => {
    try { return localStorage.getItem(key); } catch { return null; }
  };
  const store = (key, value) => {
    try { localStorage.setItem(key, value); return true; } catch { return false; }
  };
  const removeStored = (key) => {
    try { localStorage.removeItem(key); } catch { /* storage unavailable */ }
  };

  async function request(action, { method = 'GET', body, token, query } = {}) {
    const url = new URL('api.php', window.location.href);
    url.searchParams.set('action', action);
    if (query) for (const [key, value] of Object.entries(query)) url.searchParams.set(key, value);
    const options = {
      method,
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    };
    if (token) options.headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    const started = Date.now();
    let response;
    try {
      response = await fetch(url, options);
    } catch {
      throw new Error('Impossible de joindre le serveur. Vérifie ta connexion.');
    }
    let result;
    try { result = await response.json(); }
    catch { throw new Error(`Réponse incorrecte du serveur (HTTP ${response.status}).`); }
    if (!response.ok) throw new Error(result.error || `Erreur HTTP ${response.status}.`);
    return { result, estimatedClientMidpoint: (started + Date.now()) / 2 };
  }

  createApp({
    setup() {
      const mode = ref('home');
      const code = ref('');
      const hostToken = ref('');
      const playerToken = ref('');
      const adminKey = ref('');
      const manualCode = ref('');
      const playerName = ref('');
      const quizInfo = ref(null);
      const session = ref(null);
      const question = ref(null);
      const reveal = ref(null);
      const me = ref(null);
      const participants = ref([]);
      const leaderboard = ref([]);
      const online = ref(true);
      const busy = ref(false);
      const error = ref('');
      const notice = ref('');
      const clockOffset = ref(0);
      const tick = ref(Date.now());
      let pollTimer = null;
      let tickTimer = null;
      let noticeTimer = null;
      let polling = false;

      const isLocalHost = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);
      const joinUrl = computed(() => `${location.origin}${location.pathname}?join=${encodeURIComponent(code.value)}`);
      const remainingMs = computed(() => {
        void tick.value;
        if (!question.value || !session.value || session.value.status !== 'question') return 0;
        return Math.max(0, question.value.deadlineAtMs - (Date.now() + clockOffset.value));
      });
      const secondsLeft = computed(() => Math.ceil(remainingMs.value / 1000));
      const progressPercent = computed(() => {
        if (!question.value) return 0;
        if (session.value?.status === 'reveal') return 0;
        return Math.min(100, Math.max(0, 100 * remainingMs.value / (question.value.durationSeconds * 1000)));
      });
      const podiumOrder = computed(() => {
        const byRank = leaderboard.value.slice(0, 3);
        return byRank.length === 3 ? [byRank[1], byRank[0], byRank[2]] : byRank;
      });
      const shapes = ['▲', '◆', '●', '■'];
      const medals = ['🥇', '🥈', '🥉'];

      function setNotice(text) {
        notice.value = text;
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(() => { notice.value = ''; }, 3500);
      }
      function showError(exception) {
        error.value = exception?.message || 'Une erreur inattendue est survenue.';
      }
      function resetView() {
        session.value = null;
        question.value = null;
        me.value = null;
        reveal.value = null;
        participants.value = [];
        leaderboard.value = [];
        online.value = true;
        error.value = '';
        notice.value = '';
      }
      function routeTo(target, sessionCode = '') {
        resetView();
        mode.value = target;
        code.value = sessionCode.trim().toUpperCase();
        history.pushState({}, '', target === 'home' ? location.pathname : `${location.pathname}?${target === 'host' ? 'host' : 'join'}=${encodeURIComponent(code.value)}`);
        if (target === 'host') {
          hostToken.value = getStored(`quiz_host_${code.value}`) || '';
        } else if (target === 'player') {
          loadPlayerIdentity();
        }
        void refresh();
      }
      function goHome() {
        hostToken.value = '';
        playerToken.value = '';
        routeTo('home');
      }
      function loadPlayerIdentity() {
        playerToken.value = '';
        const saved = getStored(`quiz_player_${code.value}`);
        if (!saved) return;
        try {
          const identity = JSON.parse(saved);
          if (typeof identity.token === 'string' && /^[a-f0-9]{64}$/.test(identity.token)) {
            playerToken.value = identity.token;
            playerName.value = identity.name || '';
          }
        } catch { removeStored(`quiz_player_${code.value}`); }
      }
      function openJoin() {
        const candidate = manualCode.value.trim().toUpperCase();
        if (!/^[A-HJ-NP-Z2-9]{6}$/.test(candidate)) {
          error.value = 'Saisis un code à 6 caractères valide.';
          return;
        }
        routeTo('player', candidate);
      }
      async function createSession() {
        if (busy.value) return;
        busy.value = true;
        error.value = '';
        try {
          const { result } = await request('create', { method: 'POST', token: adminKey.value, body: {} });
          hostToken.value = result.hostToken;
          store(`quiz_host_${result.code}`, hostToken.value);
          adminKey.value = '';
          routeTo('host', result.code);
          // Si localStorage est désactivé, conserver le jeton en mémoire pour la session actuelle.
          hostToken.value = result.hostToken;
          await refresh();
        } catch (exception) { showError(exception); }
        finally { busy.value = false; }
      }
      async function joinSession() {
        if (busy.value) return;
        busy.value = true;
        error.value = '';
        try {
          const { result } = await request('join', { method: 'POST', body: { code: code.value, name: playerName.value } });
          playerToken.value = result.playerToken;
          playerName.value = result.name;
          store(`quiz_player_${code.value}`, JSON.stringify({ token: result.playerToken, name: result.name }));
          await refresh();
        } catch (exception) { showError(exception); }
        finally { busy.value = false; }
      }
      async function refresh() {
        if (polling) return;
        if (mode.value === 'home') return;
        const activeToken = mode.value === 'host' ? hostToken.value : playerToken.value;
        if (!activeToken) return;
        polling = true;
        const activeCode = code.value;
        const activeMode = mode.value;
        try {
          const { result, estimatedClientMidpoint } = await request('state', { token: activeToken, query: { code: activeCode } });
          if (code.value !== activeCode || mode.value !== activeMode) return;
          clockOffset.value = result.serverNowMs - estimatedClientMidpoint;
          session.value = result.session;
          question.value = result.question || null;
          reveal.value = result.reveal || null;
          me.value = result.me || null;
          participants.value = result.participants || [];
          leaderboard.value = result.leaderboard || [];
          online.value = true;
          if (error.value.startsWith('Impossible de joindre')) error.value = '';
        } catch (exception) {
          if (code.value !== activeCode || mode.value !== activeMode) return;
          online.value = false;
          if (exception.message.includes('inconnu') || exception.message.includes('non autorisé')) {
            if (activeMode === 'player') {
              removeStored(`quiz_player_${activeCode}`);
              playerToken.value = '';
              resetView();
            } else {
              hostToken.value = '';
              resetView();
            }
          }
          showError(exception);
        } finally { polling = false; }
      }
      async function submitAnswer(index) {
        if (busy.value || mode.value !== 'player' || !question.value || me.value?.hasAnswered
          || session.value?.status !== 'question' || remainingMs.value <= 0) return;
        busy.value = true;
        error.value = '';
        try {
          await request('answer', {
            method: 'POST', token: playerToken.value,
            body: { code: code.value, questionIndex: session.value.questionIndex, answerIndex: index },
          });
          // Réactivité immédiate pour empêcher un double tap pendant le prochain polling.
          me.value = { ...me.value, hasAnswered: true, answerIndex: index };
          await refresh();
        } catch (exception) {
          showError(exception);
          await refresh();
        } finally { busy.value = false; }
      }
      async function hostCommand(command) {
        if (busy.value || mode.value !== 'host') return;
        busy.value = true;
        error.value = '';
        try {
          await request('host', { method: 'POST', token: hostToken.value, body: { code: code.value, command } });
          await refresh();
        } catch (exception) { showError(exception); }
        finally { busy.value = false; }
      }
      function confirmFinish() {
        if (window.confirm('Terminer maintenant ? Les joueurs verront le podium et la session ne pourra plus être reprise.')) {
          void hostCommand('finish');
        }
      }
      async function copyJoinLink() {
        try {
          await navigator.clipboard.writeText(joinUrl.value);
          setNotice('Lien copié !');
        } catch { setNotice('Copie le lien affiché sous le QR code.'); }
      }
      function renderQRCode() {
        if (mode.value !== 'host' || session.value?.status !== 'lobby') return;
        const element = document.getElementById('qr-code');
        if (!element) return;
        element.replaceChildren();
        if (!window.QRCode) {
          element.textContent = 'Bibliothèque QR code indisponible. Utilise le lien ci-dessous.';
          return;
        }
        try {
          new window.QRCode(element, {
            text: joinUrl.value, width: 224, height: 224,
            colorDark: '#14213b', colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
          });
          const img = element.querySelector('img');
          if (img) img.alt = `QR code pour rejoindre la session ${code.value}`;
        } catch { element.textContent = 'QR code indisponible. Utilise le lien ci-dessous.'; }
      }
      async function restoreFromUrl() {
        const params = new URLSearchParams(location.search);
        const isHost = params.has('host');
        const candidate = (isHost ? params.get('host') : params.get('join')) || '';
        if (!candidate) {
          resetView();
          mode.value = 'home';
          code.value = '';
          hostToken.value = '';
          playerToken.value = '';
          return;
        }
        resetView();
        code.value = candidate.toUpperCase();
        mode.value = isHost ? 'host' : 'player';
        if (isHost) hostToken.value = getStored(`quiz_host_${code.value}`) || '';
        else loadPlayerIdentity();
        await refresh();
      }

      watch([mode, code, () => session.value?.status], () => { void nextTick(renderQRCode); });
      onMounted(() => {
        void restoreFromUrl();
        void request('info').then(({ result }) => { quizInfo.value = result; }).catch((exception) => {
          if (mode.value === 'home') showError(exception);
        });
        tickTimer = setInterval(() => { tick.value = Date.now(); }, 100);
        pollTimer = setInterval(() => { void refresh(); }, 1000);
        window.addEventListener('popstate', restoreFromUrl);
      });
      onUnmounted(() => {
        clearInterval(tickTimer);
        clearInterval(pollTimer);
        clearTimeout(noticeTimer);
        window.removeEventListener('popstate', restoreFromUrl);
      });
      return {
        mode, code, hostToken, adminKey, manualCode, playerName, quizInfo,
        session, question, reveal, me, participants, leaderboard,
        online, busy, error, notice, isLocalHost, joinUrl, remainingMs, secondsLeft, progressPercent,
        podiumOrder, shapes, medals, letters,
        goHome, openJoin, createSession, joinSession, submitAnswer, hostCommand,
        confirmFinish, copyJoinLink,
      };
    },
  }).mount('#app');
})();

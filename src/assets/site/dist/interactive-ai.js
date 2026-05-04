(function() {
  const config = window.InteractiveAIConfig || {};
  const storageKey = "interactive-ai-state";
  const productName = String(config.productName || "Site Assist");
  const assistantName = String(config.assistantName || productName);

  const state = loadState();
  let shell;
  let messagesEl;
  let textareaEl;
  let micButtonEl;
  let voiceToggleEl;
  let statusLabelEl;
  let subtitleEl;
  let recognition;
  let typingMessageEl;
  let recognitionTranscript = "";
  let availableVoices = [];
  let selectedVoice = null;
  let speechAvailable = false;
  let voiceRestartTimer = null;
  let lastStatusText = "";
  let activeTypeTimer = null;
  let activeScrollFrame = null;
  let activeRequestController = null;

  init();

  function init() {
    if (!document.body) {
      return;
    }

    createUi();
    setupVoices();
    renderConversation();

    if (!state.conversation.length) {
      appendAssistantMessage(
        config.welcomeMessage || ("Hi, I am " + assistantName + ". I am here to help you explore the website, find what you need, and make things easier."),
        { suggestions: buildStarterSuggestions() }
      );
    }

    resumeAfterNavigationIfNeeded();
  }

  function loadState() {
    try {
      const parsed = JSON.parse(localStorage.getItem(storageKey) || "{}");
      return {
        conversation: Array.isArray(parsed.conversation) ? parsed.conversation : [],
        profile: parsed.profile && typeof parsed.profile === "object" ? parsed.profile : {},
        memory: parsed.memory && typeof parsed.memory === "object"
          ? parsed.memory
          : { voiceReplies: false, continuousVoice: false },
      };
    } catch (error) {
      return {
        conversation: [],
        profile: {},
        memory: { voiceReplies: false, continuousVoice: false },
      };
    }
  }

  function saveState() {
    localStorage.setItem(storageKey, JSON.stringify(state));
  }

  function createUi() {
    const launcher = document.createElement("button");
    launcher.className = "interactive-ai-launcher";
    launcher.type = "button";
    launcher.setAttribute("aria-label", "Open " + productName);
    launcher.innerHTML = `
      <span class="interactive-ai-launcher-ring is-one" aria-hidden="true"></span>
      <span class="interactive-ai-launcher-ring is-two" aria-hidden="true"></span>
      <span class="interactive-ai-launcher-core" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 3C6.925 3 3 6.614 3 11c0 2.039.871 3.874 2.319 5.237L4.6 21l4.246-2.121A10.84 10.84 0 0 0 12 19c5.075 0 9-3.614 9-8s-3.925-8-9-8Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M8.25 11h.008M12 11h.008M15.75 11h.008" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
    `;
    launcher.addEventListener("click", openModal);

    shell = document.createElement("div");
    shell.className = "interactive-ai-shell";
    shell.innerHTML = `
      <div class="interactive-ai-modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(productName)} assistant">
        <div class="interactive-ai-header">
          <div class="interactive-ai-header-grid" aria-hidden="true"></div>
          <div class="interactive-ai-header-orbit" aria-hidden="true"></div>
          <div class="interactive-ai-header-row">
            <div class="interactive-ai-presence">
              <div class="interactive-ai-avatar" aria-hidden="true">
                <div class="interactive-ai-avatar-core">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="3.1" fill="currentColor"></circle>
                    <path d="M4 12c1.62-3.12 4.29-5.05 8-5.05S18.38 8.88 20 12c-1.62 3.12-4.29 5.05-8 5.05S5.62 15.12 4 12Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 3.4v2.2M12 18.4v2.2M3.4 12h2.2M18.4 12h2.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                  </svg>
                </div>
                <span class="interactive-ai-avatar-pulse" aria-hidden="true"></span>
              </div>
              <div>
                <h2 class="interactive-ai-title">${escapeHtml(productName)}</h2>
                <p class="interactive-ai-subtitle">Neural guide for this site, its live content, and quick outside facts when you need them.</p>
                <div class="interactive-ai-status">
                  <span class="interactive-ai-status-dot" aria-hidden="true"></span>
                  <span class="interactive-ai-status-label">Neural channel open</span>
                </div>
              </div>
            </div>
            <button class="interactive-ai-close" type="button" aria-label="Close assistant">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M7 7L17 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                <path d="M17 7L7 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
              </svg>
            </button>
          </div>
        </div>
        <div class="interactive-ai-messages"></div>
        <div class="interactive-ai-input">
          <div class="interactive-ai-input-row">
            <textarea class="interactive-ai-textarea" placeholder="Ask me anything about this website"></textarea>
            <button class="interactive-ai-icon-button" type="button" aria-label="Use voice input">
              <span class="interactive-ai-mic-live" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
              </span>
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="9" y="3.5" width="6" height="10" rx="3" stroke="currentColor" stroke-width="1.8"/>
                <path d="M6.5 10.5a5.5 5.5 0 1 0 11 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M12 16v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M9 20.5h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              </svg>
            </button>
            <button class="interactive-ai-send" type="button" aria-label="Send message">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4 12.2L19 4.8L14.2 19.2L11.1 13.8L4 12.2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <path d="M11 13.5L19 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
              </svg>
            </button>
          </div>
          <div class="interactive-ai-voice-row">
            <button class="interactive-ai-voice-toggle" type="button" aria-pressed="false">
              <span class="interactive-ai-voice-toggle-dot" aria-hidden="true"></span>
              <span class="interactive-ai-voice-toggle-label">Voice replies off</span>
            </button>
          </div>
          <div class="interactive-ai-footnote">Session memory stays with you as you move through the site.</div>
        </div>
      </div>
    `;

    shell.addEventListener("click", function(event) {
      if (event.target === shell) {
        closeModal();
      }
    });

    shell.querySelector(".interactive-ai-close").addEventListener("click", closeModal);
    messagesEl = shell.querySelector(".interactive-ai-messages");
    textareaEl = shell.querySelector(".interactive-ai-textarea");
    micButtonEl = shell.querySelector(".interactive-ai-icon-button");
    voiceToggleEl = shell.querySelector(".interactive-ai-voice-toggle");
    statusLabelEl = shell.querySelector(".interactive-ai-status-label");
    subtitleEl = shell.querySelector(".interactive-ai-subtitle");

    shell.querySelector(".interactive-ai-send").addEventListener("click", sendCurrentMessage);
    textareaEl.addEventListener("keydown", function(event) {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        sendCurrentMessage();
      }
    });

    micButtonEl.addEventListener("click", toggleVoiceInput);
    voiceToggleEl.addEventListener("click", toggleVoiceReplies);

    document.body.appendChild(launcher);
    document.body.appendChild(shell);
    refreshVoiceUi();
    refreshMicUi();
  }

  function openModal() {
    shell.classList.add("is-open");
    refreshAdaptiveUi();
    setMood("welcoming");
    setTimeout(function() {
      scrollMessagesToLatest();
      textareaEl.focus();
    }, 40);
  }

  function closeModal() {
    if (voiceRestartTimer) {
      window.clearTimeout(voiceRestartTimer);
      voiceRestartTimer = null;
    }
    shell.classList.remove("is-open");
    shell.classList.remove("is-thinking", "is-listening", "is-speaking");
    stopSpeaking();
    setStatus("Neural channel open");
    refreshAdaptiveUi();
    refreshMicUi();
  }

  function renderConversation() {
    messagesEl.innerHTML = "";
    state.conversation.forEach(function(item) {
      renderMessage(item, true);
    });
    scrollMessagesToLatest();
  }

  function renderMessage(item, instant) {
    const wrapper = document.createElement("div");
    wrapper.className = "interactive-ai-message " + (item.role === "user" ? "is-user" : "is-assistant");
    const contentEl = document.createElement("span");
    contentEl.className = "interactive-ai-message-content";
    wrapper.appendChild(contentEl);

    if (item.role === "assistant" && !instant) {
      typeAssistantMessage(contentEl, String(item.content || ""), function() {
        attachAssistantControls(wrapper, item);
      });
    } else {
      applyFormattedMessageContent(contentEl, String(item.content || ""));
      attachAssistantControls(wrapper, item);
    }

    messagesEl.appendChild(wrapper);
  }

  function attachAssistantControls(wrapper, item) {
    if (item.role === "assistant" && Array.isArray(item.actions) && item.actions.length) {
      const actionsEl = document.createElement("div");
      actionsEl.className = "interactive-ai-actions is-revealing";
      item.actions.forEach(function(action) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "interactive-ai-chip is-action is-pending";
        button.textContent = action.label || action.type;
        button.addEventListener("click", function() {
          handleAction(action);
        });
        actionsEl.appendChild(button);
      });
      wrapper.appendChild(actionsEl);
      revealChipGroup(actionsEl);
    }

    if (item.role === "assistant" && Array.isArray(item.suggestions) && item.suggestions.length) {
      const actionLabels = new Set(
        Array.isArray(item.actions)
          ? item.actions.map(function(action) {
              return normalizeUiOption(action.label || action.type || "");
            }).filter(Boolean)
          : []
      );
      const suggestionsEl = document.createElement("div");
      suggestionsEl.className = "interactive-ai-suggestions is-revealing";
      item.suggestions.forEach(function(suggestion) {
        if (!suggestion || actionLabels.has(normalizeUiOption(suggestion))) {
          return;
        }

        const button = document.createElement("button");
        button.type = "button";
        button.className = "interactive-ai-chip is-pending";
        button.textContent = suggestion;
        button.addEventListener("click", function() {
          const normalizedSuggestion = normalizeUiOption(suggestion);

          if (normalizedSuggestion === "fill the form with my details") {
            handleAction({
              type: "fill_form",
              formIndex: 0,
              fields: {},
              profile: state.profile || {},
              label: suggestion
            });
            return;
          }

          if (normalizedSuggestion === "go to the form" || normalizedSuggestion === "take me to the form") {
            handleAction({
              type: "scroll_to_form",
              formIndex: 0,
              label: suggestion
            });
            return;
          }

          if (isPromptStarterSuggestion(suggestion)) {
            const starter = suggestion.replace(/\.\.\.\s*$/, "").trim();
            state.memory.pendingPromptStarter = starter;
            saveState();
            textareaEl.value = starter + " ";
            textareaEl.focus();
            textareaEl.setSelectionRange(textareaEl.value.length, textareaEl.value.length);
            setStatus("Add the detail and send when you are ready");
            return;
          }

          delete state.memory.pendingPromptStarter;
          saveState();
          textareaEl.value = suggestion;
          sendCurrentMessage();
        });
        suggestionsEl.appendChild(button);
      });
      wrapper.appendChild(suggestionsEl);
      revealChipGroup(suggestionsEl);
    }
  }

  function appendUserMessage(content) {
    removeTypingIndicator();
    const item = { role: "user", content: content };
    state.conversation.push(item);
    saveState();
    renderMessage(item);
    scrollMessagesToLatest();
    setMood("thinking");
  }

  function appendAssistantMessage(content, extra) {
    removeTypingIndicator();
    const item = {
      role: "assistant",
      content: content,
      actions: extra && Array.isArray(extra.actions) ? extra.actions : [],
      suggestions: extra && Array.isArray(extra.suggestions) ? extra.suggestions : [],
    };
    state.conversation.push(item);
    saveState();
    renderMessage(item, false);
    scrollMessagesToLatest();
    setMood("friendly");
  }

  function typeAssistantMessage(element, content, onDone) {
    if (!element) {
      if (typeof onDone === "function") {
        onDone();
      }
      return;
    }

    if (activeTypeTimer) {
      window.clearTimeout(activeTypeTimer);
      activeTypeTimer = null;
    }

    const text = String(content || "");
    const segments = parseFormattedMessageSegments(text);
    const visibleText = segments.map(function(segment) {
      return segment.text;
    }).join("");

    if (!text) {
      applyFormattedMessageContent(element, "");
      if (typeof onDone === "function") {
        onDone();
      }
      return;
    }

    element.textContent = "";
    element.classList.add("is-typing-text");

    let visibleIndex = 0;

    function step() {
      visibleIndex += nextChunkSize(visibleText, visibleIndex);
      renderFormattedMessageSlice(element, segments, visibleIndex);
      scrollMessagesToLatest();

      if (visibleIndex >= visibleText.length) {
        element.classList.remove("is-typing-text");
        applyFormattedMessageContent(element, text);
        activeTypeTimer = null;
        if (typeof onDone === "function") {
          onDone();
        }
        if (shouldSpeakReplies()) {
          speakReply(visibleText);
        }
        return;
      }

      activeTypeTimer = window.setTimeout(step, nextTypingDelay(visibleText, visibleIndex));
    }

    activeTypeTimer = window.setTimeout(step, 18);
  }

  function applyFormattedMessageContent(element, content) {
    if (!element) {
      return;
    }

    const text = String(content || "");
    const segments = parseFormattedMessageSegments(text);
    renderFormattedMessageSlice(element, segments, Number.MAX_SAFE_INTEGER);
  }

  function parseFormattedMessageSegments(text) {
    const parts = String(text || "").split(/(\*[^\s*\n](?:[^*\n]*[^\s*\n])?\*)/g);

    return parts.reduce(function(segments, part) {
      if (!part) {
        return segments;
      }

      if (/^\*[^\s*\n](?:[^*\n]*[^\s*\n])?\*$/.test(part)) {
        segments.push({
          text: part.slice(1, -1),
          italic: true,
        });
        return segments;
      }

      segments.push({
        text: part,
        italic: false,
      });
      return segments;
    }, []);
  }

  function renderFormattedMessageSlice(element, segments, visibleChars) {
    if (!element) {
      return;
    }

    element.textContent = "";
    const fragment = document.createDocumentFragment();
    let remaining = Math.max(0, visibleChars);

    segments.forEach(function(segment) {
      if (!segment || !segment.text || remaining <= 0) {
        return;
      }

      const slice = segment.text.slice(0, remaining);
      if (!slice) {
        return;
      }

      if (segment.italic) {
        const em = document.createElement("em");
        em.textContent = slice;
        fragment.appendChild(em);
      } else {
        fragment.appendChild(document.createTextNode(slice));
      }

      remaining -= slice.length;
    });

    element.appendChild(fragment);
  }

  function nextChunkSize(text, index) {
    const char = text.charAt(index);
    if (/\s/.test(char)) {
      return 1;
    }

    const nextSlice = text.slice(index, index + 6);
    const wordMatch = nextSlice.match(/^[^\s]+/);
    if (wordMatch && wordMatch[0].length <= 4) {
      return wordMatch[0].length;
    }

    return 1;
  }

  function nextTypingDelay(text, index) {
    const previous = text.charAt(Math.max(0, index - 1));
    if (/[.!?]/.test(previous)) {
      return 52;
    }
    if (/[,:;]/.test(previous)) {
      return 34;
    }
    return 14;
  }

  function refreshAdaptiveUi() {
    if (!subtitleEl) {
      return;
    }

    subtitleEl.textContent = buildAdaptiveSubtitle();
  }

  function buildAdaptiveSubtitle() {
    const pageContext = getPageContext();
    const title = String(pageContext.title || "").trim();
    const hasForm = Array.isArray(pageContext.forms) && pageContext.forms.length > 0;
    const interest = String(state.memory.interest || "").trim();

    if (interest && title) {
      return "Keeping an eye on " + interest + " while helping you around " + title + ".";
    }

    if (interest) {
      return "Keeping an eye on " + interest + " as we explore the site together.";
    }

    if (hasForm && title) {
      return "Ready to help you explore " + title + " and the rest of the site.";
    }

    if (hasForm) {
      return "Ready to help you explore the page and find your way around the site.";
    }

    if (title) {
      return "Ready to help you explore " + title + " and the rest of the site.";
    }

    return "Neural guide for this site, its live content, and quick outside facts when you need them.";
  }

  function buildStarterSuggestions() {
    const suggestions = [];
    const pageContext = getPageContext();
    const title = String(pageContext.title || "").trim();
    const links = Array.isArray(pageContext.links) ? pageContext.links : [];
    const hasForm = Array.isArray(pageContext.forms) && pageContext.forms.length > 0;
    const interest = String(state.memory.interest || "").trim();

    if (interest) {
      pushStarterSuggestion(suggestions, "Help me find " + interest);
    }

    if (title) {
      pushStarterSuggestion(suggestions, "What is " + title + " about?");
    } else {
      pushStarterSuggestion(suggestions, "What is on this page?");
    }

    if (hasForm && hasMeaningfulProfile()) {
      pushStarterSuggestion(suggestions, hasMeaningfulProfile() ? "Fill the form with my details" : "Help me with this form");
    }

    links.slice(0, 2).forEach(function(link) {
      const text = String(link && link.text || "").trim();
      if (text) {
        pushStarterSuggestion(suggestions, "Take me to " + text);
      }
    });

    pushStarterSuggestion(suggestions, "What should I look at first?");

    return suggestions.slice(0, 4);
  }

  function buildPostNavigationSuggestions() {
    const suggestions = [];
    const pageContext = getPageContext();
    const title = String(pageContext.title || "").trim();
    const links = Array.isArray(pageContext.links) ? pageContext.links : [];
    const hasForm = Array.isArray(pageContext.forms) && pageContext.forms.length > 0;

    if (title) {
      pushStarterSuggestion(suggestions, "What is " + title + " about?");
    } else {
      pushStarterSuggestion(suggestions, "What is on this page?");
    }

    if (hasForm && hasMeaningfulProfile()) {
      pushStarterSuggestion(suggestions, hasMeaningfulProfile() ? "Fill the form with my details" : "Help me with this form");
    }

    links.slice(0, 2).forEach(function(link) {
      const text = String(link && link.text || "").trim();
      if (text) {
        pushStarterSuggestion(suggestions, "Take me to " + text);
      }
    });

    pushStarterSuggestion(suggestions, "What should I do next?");

    return suggestions.slice(0, 4);
  }

  function rememberPendingNavigation(action) {
    state.memory.reopenAfterNavigation = {
      wasOpen: !!(shell && shell.classList.contains("is-open")),
      label: String(action && action.label || "").trim(),
      url: String(action && action.url || "").trim(),
      createdAt: Date.now(),
    };
    saveState();
  }

  function resumeAfterNavigationIfNeeded() {
    const pending = state.memory && state.memory.reopenAfterNavigation;
    if (!pending || !pending.wasOpen) {
      return;
    }

    const age = Date.now() - Number(pending.createdAt || 0);
    delete state.memory.reopenAfterNavigation;
    saveState();

    if (age > 20000) {
      return;
    }

    window.setTimeout(function() {
      openModal();
      appendAssistantMessage(buildNavigationFollowUpMessage(pending), {
        suggestions: buildPostNavigationSuggestions(),
      });
    }, 260);
  }

  function buildNavigationFollowUpMessage(pending) {
    const pageContext = getPageContext();
    const title = String(pageContext.title || "").trim();
    const target = String(pending && pending.label || "")
      .replace(/^open\s+/i, "")
      .replace(/^go to\s+/i, "")
      .trim();

    if (title) {
      return "We made it to " + title + ". What would you like me to do next?";
    }

    if (target) {
      return "We made it to " + target + ". What would you like me to do next?";
    }

    return "We made it here. What would you like me to do next?";
  }

  function pushStarterSuggestion(suggestions, suggestion) {
    const value = String(suggestion || "").trim();
    if (!value) {
      return;
    }

    const normalized = normalizeUiOption(value);
    if (!normalized) {
      return;
    }

    const exists = suggestions.some(function(item) {
      return normalizeUiOption(item) === normalized;
    });

    if (!exists) {
      suggestions.push(value);
    }
  }

  function hasMeaningfulProfile() {
    return Boolean(
      state.profile
      && (state.profile.name || state.profile.email || state.profile.phone || state.profile.company || state.profile.message)
    );
  }

  async function sendCurrentMessage() {
    const rawMessage = textareaEl.value.trim();
    if (!rawMessage) {
      return;
    }

    const message = applyPendingPromptStarter(rawMessage);

    textareaEl.value = "";
    updateProfileFromMessage(message);
    appendUserMessage(message);
    showTypingIndicator();
    setStatus(getThinkingStatus(message));
    delete state.memory.pendingPromptStarter;
    saveState();

    try {
      if (activeRequestController) {
        activeRequestController.abort();
      }

      activeRequestController = typeof AbortController === "function" ? new AbortController() : null;
      const timeoutId = window.setTimeout(function() {
        if (activeRequestController) {
          activeRequestController.abort();
        }
      }, 15000);

      const response = await fetch(config.actionUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
        },
        signal: activeRequestController ? activeRequestController.signal : undefined,
        body: JSON.stringify({
          message: message,
          conversation: state.conversation.slice(-12).map(function(item) {
            return { role: item.role, content: item.content };
          }),
          pageContext: getPageContext(),
          profile: state.profile,
          memory: state.memory,
        }),
      });
      window.clearTimeout(timeoutId);
      activeRequestController = null;

      const data = await response.json();
      if (Array.isArray(data.actions) && data.actions.some(function(action) { return action && action.type === "clear_profile"; })) {
        clearSavedProfile();
      }
      setStatus("Here is something helpful");
      appendAssistantMessage(data.message || "I am sorry, I could not prepare a reply just now.", data);
    } catch (error) {
      activeRequestController = null;
      setStatus("Signal flickered, trying again");
      appendAssistantMessage(
        error && error.name === "AbortError"
          ? "I took a little too long to answer that. Please try again, and I will keep it lighter."
          : "I am sorry, I am having a little trouble right now. Please try again in a moment."
      );
    }
  }

  function showTypingIndicator() {
    removeTypingIndicator();
    shell.classList.add("is-thinking");

    typingMessageEl = document.createElement("div");
    typingMessageEl.className = "interactive-ai-message is-assistant is-typing";
    typingMessageEl.innerHTML = '<div class="interactive-ai-typing" aria-label="Assistant is typing"><span></span><span></span><span></span></div>';
    messagesEl.appendChild(typingMessageEl);
    scrollMessagesToLatest();
  }

  function removeTypingIndicator() {
    if (typingMessageEl && typingMessageEl.parentNode) {
      typingMessageEl.parentNode.removeChild(typingMessageEl);
    }
    typingMessageEl = null;
    shell.classList.remove("is-thinking");
  }

  function scrollMessagesToLatest() {
    if (!messagesEl) {
      return;
    }

    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function smoothScrollMessagesToLatest(duration) {
    if (!messagesEl) {
      return;
    }

    if (activeScrollFrame) {
      window.cancelAnimationFrame(activeScrollFrame);
      activeScrollFrame = null;
    }

    const startTop = messagesEl.scrollTop;
    const targetTop = messagesEl.scrollHeight - messagesEl.clientHeight;
    const travel = Math.max(0, targetTop - startTop);

    if (travel <= 2) {
      messagesEl.scrollTop = targetTop;
      return;
    }

    const startTime = performance.now();
    const totalDuration = typeof duration === "number" ? duration : 220;

    function step(now) {
      const progress = Math.min(1, (now - startTime) / totalDuration);
      const eased = 1 - Math.pow(1 - progress, 3);
      messagesEl.scrollTop = startTop + (travel * eased);

      if (progress < 1) {
        activeScrollFrame = window.requestAnimationFrame(step);
      } else {
        activeScrollFrame = null;
      }
    }

    activeScrollFrame = window.requestAnimationFrame(step);
  }

  function revealChipGroup(groupEl) {
    if (!groupEl) {
      return;
    }

    const chips = Array.from(groupEl.querySelectorAll(".interactive-ai-chip"));
    if (!chips.length) {
      groupEl.classList.remove("is-revealing");
      return;
    }

    chips.forEach(function(chip, index) {
      window.setTimeout(function() {
        chip.classList.remove("is-pending");
        chip.classList.add("is-visible");
        smoothScrollMessagesToLatest(180);

        if (index === chips.length - 1) {
          window.setTimeout(function() {
            groupEl.classList.remove("is-revealing");
          }, 180);
        }
      }, index * 85);
    });
  }

  function setupVoices() {
    if (!("speechSynthesis" in window) || typeof window.SpeechSynthesisUtterance !== "function") {
      speechAvailable = false;
      return;
    }

    speechAvailable = true;
    availableVoices = window.speechSynthesis.getVoices();
    selectedVoice = pickPreferredVoice(availableVoices);

    if (typeof window.speechSynthesis.onvoiceschanged !== "undefined") {
      window.speechSynthesis.onvoiceschanged = function() {
        availableVoices = window.speechSynthesis.getVoices();
        selectedVoice = pickPreferredVoice(availableVoices);
        refreshVoiceUi();
      };
    }
  }

  function getPageContext() {
    const visitorTimezone = getVisitorTimezone();
    const headings = Array.from(document.querySelectorAll("h1, h2, h3"))
      .map(function(node) { return node.textContent.trim(); })
      .filter(Boolean)
      .slice(0, 8);

    const links = Array.from(document.querySelectorAll("a[href]"))
      .map(function(link) {
        const href = link.getAttribute("href") || "";
        const text = link.textContent.trim();
        if (!href || href.startsWith("#") || !text) {
          return null;
        }

        return {
          text: text,
          url: link.href,
        };
      })
      .filter(Boolean)
      .slice(0, 20);

    const forms = Array.from(document.querySelectorAll("form")).map(function(form, index) {
      const fields = Array.from(form.querySelectorAll("input, textarea, select")).map(function(field) {
        if (shouldIgnoreField(field)) {
          return null;
        }

        const label = getFieldLabel(field);
        return {
          name: field.name || field.id || "",
          label: label,
          type: (field.type || field.tagName || "").toLowerCase(),
          required: field.required,
          placeholder: field.placeholder || "",
        };
      }).filter(function(field) {
        return field && field.name;
      });

      return {
        index: index,
        id: form.id || "",
        action: form.getAttribute("action") || "",
        method: (form.getAttribute("method") || "get").toLowerCase(),
        fields: fields,
      };
    });

    const contentRoot = document.querySelector("main") || document.body;
    const contentExcerpt = (contentRoot.innerText || "")
      .replace(/\s+/g, " ")
      .trim()
      .slice(0, 1400);

    return {
      url: window.location.href,
      title: document.title,
      description: document.querySelector('meta[name="description"]')?.content || "",
      visitorTimezone: visitorTimezone,
      headings: headings,
      links: links,
      forms: forms,
      contentExcerpt: contentExcerpt,
    };
  }

  function getVisitorTimezone() {
    try {
      const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      return typeof timezone === "string" && timezone.trim() ? timezone.trim() : "";
    } catch (error) {
      return "";
    }
  }

  function getFieldLabel(field) {
    if (field.labels && field.labels[0]) {
      return field.labels[0].textContent.trim();
    }

    if (field.id) {
      const label = document.querySelector('label[for="' + CSS.escape(field.id) + '"]');
      if (label) {
        return label.textContent.trim();
      }
    }

    return field.getAttribute("aria-label") || field.placeholder || field.name || "";
  }

  function shouldIgnoreField(field) {
    const type = (field.type || field.tagName || "").toLowerCase();
    const name = (field.name || "").toLowerCase();
    const id = (field.id || "").toLowerCase();

    if (!field.name && !field.id) {
      return true;
    }

    if (field.disabled || field.hidden || type === "hidden") {
      return true;
    }

    if (["submit", "button", "reset", "image", "file"].includes(type)) {
      return true;
    }

    const blockedFragments = [
      "csrf",
      "token",
      "action",
      "redirect",
      "submitaction",
      "siteid",
      "formid",
      "handle"
    ];

    return blockedFragments.some(function(fragment) {
      return name.includes(fragment) || id.includes(fragment);
    });
  }

  function updateProfileFromMessage(message) {
    const explicitField = extractExplicitFieldValue(message);
    if (explicitField) {
      storeProfileFieldValue(explicitField.field, explicitField.value);
    }

    const email = message.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
    if (email) {
      state.profile.email = email[0];
    }

    const phone = message.match(/(\+?\d[\d\s().-]{7,}\d)/);
    if (phone) {
      state.profile.phone = phone[0].trim();
    }

    const name = extractLikelyName(message);
    if (name) {
      state.profile.name = name.trim().replace(/\s+/g, " ");
      const nameParts = state.profile.name.split(/\s+/);
      state.profile.firstName = nameParts[0] || "";
      state.profile.lastName = nameParts.length > 1 ? nameParts.slice(1).join(" ") : "";
      state.memory.visitorName = state.profile.name;
    }

    const company = message.match(/\b(?:company is|work at|from)\s+([a-z0-9][a-z0-9\s&'.-]{1,60})/i);
    if (company) {
      state.profile.company = company[1].trim().replace(/\s+/g, " ");
    }

    if (!state.profile.message && message.length > 24 && !looksLikeCommandMessage(message) && !explicitField) {
      state.profile.message = message;
    }

    const subject = message.match(/\b(?:subject is|about|regarding)\s+([a-z0-9][a-z0-9\s&',.-]{2,120})/i);
    if (subject) {
      state.profile.subject = subject[1].trim().replace(/\s+/g, " ");
    }

    rememberFromConversation(message);
    sanitizeProfileState();

    saveState();
  }

  function extractExplicitFieldValue(message) {
    const match = String(message || "").match(/^\s*my\s+(.{1,80}?)\s+is\s+(.{1,240})\s*$/i);
    if (!match) {
      return null;
    }

    const field = normalizeNameCandidate(match[1]).replace(/[.:]+$/g, "").trim();
    const value = String(match[2] || "").trim();

    if (!field || !value || looksLikeCommandMessage(value)) {
      return null;
    }

    return { field: field, value: value };
  }

  function normalizeFormFieldProfileKey(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/[*:()[\]{}]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function canonicalProfileKeyForFieldLabel(value) {
    const key = normalizeFormFieldProfileKey(value);
    if (!key) {
      return null;
    }

    if (key === "first name" || key === "firstname" || key === "first_name") {
      return "firstName";
    }

    if (key === "last name" || key === "lastname" || key === "last_name" || key === "surname") {
      return "lastName";
    }

    if (key === "name" || key === "full name" || key === "your name" || key === "contact name") {
      return "name";
    }

    if (key === "email" || key === "email address") {
      return "email";
    }

    if (key === "phone" || key === "phone number" || key === "mobile" || key === "telephone") {
      return "phone";
    }

    if (key === "company" || key === "company name" || key === "organisation" || key === "organization") {
      return "company";
    }

    if (key === "subject") {
      return "subject";
    }

    if (key === "message" || key === "comment" || key === "enquiry" || key === "inquiry") {
      return "message";
    }

    return null;
  }

  function storeProfileFieldValue(fieldLabel, value) {
    const label = normalizeFormFieldProfileKey(fieldLabel);
    const storedValue = String(value || "").trim().replace(/\s+/g, " ");

    if (!label || !storedValue) {
      return;
    }

    const canonicalKey = canonicalProfileKeyForFieldLabel(label);
    if (canonicalKey === "name") {
      state.profile.name = storedValue;
      const nameParts = storedValue.split(/\s+/);
      state.profile.firstName = nameParts[0] || "";
      state.profile.lastName = nameParts.length > 1 ? nameParts.slice(1).join(" ") : "";
      state.memory.visitorName = state.profile.name;
      return;
    }

    if (canonicalKey) {
      state.profile[canonicalKey] = storedValue;
      return;
    }

    state.profile.formFields = state.profile.formFields && typeof state.profile.formFields === "object"
      ? state.profile.formFields
      : {};
    state.profile.formFields[label] = storedValue;
  }

  function extractLikelyName(message) {
    const explicit = message.match(/\bmy name is\s+([a-z][a-z\s'-]{1,60})/i);
    if (explicit) {
      const candidate = normalizeNameCandidate(explicit[1]);
      return looksLikeHumanName(candidate) ? candidate : null;
    }

    const casual = message.match(/\b(?:i am|i'm)\s+([a-z][a-z\s'-]{1,40})/i);
    if (casual) {
      const candidate = normalizeNameCandidate(casual[1]);
      return looksLikeHumanName(candidate) ? candidate : null;
    }

    return null;
  }

  function normalizeNameCandidate(value) {
    return String(value || "")
      .trim()
      .replace(/[.!?,]+$/g, "")
      .replace(/\s+/g, " ");
  }

  function looksLikeHumanName(value) {
    const candidate = normalizeNameCandidate(value);
    if (!candidate) {
      return false;
    }

    const words = candidate.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length || words.length > 3) {
      return false;
    }

    const blockedWords = new Set([
      "a", "an", "bit", "stuck", "confused", "lost", "okay", "ok", "fine", "good", "great", "happy",
      "sad", "tired", "frustrated", "trying", "looking", "interested", "curious", "ready", "here",
      "there", "having", "feeling", "currently", "still"
    ]);

    return words.every(function(word) {
      return word.length > 1 && !blockedWords.has(word);
    });
  }

  function sanitizeProfileState() {
    if (state.profile && state.profile.name && !looksLikeHumanName(state.profile.name)) {
      delete state.profile.name;
      delete state.profile.firstName;
      delete state.profile.lastName;
      delete state.memory.visitorName;
    }
  }

  function looksLikeCommandMessage(message) {
    const normalized = String(message || "").toLowerCase().trim();

    return [
      /^fill the form with my details$/,
      /^can you help me fill this in\??$/,
      /^help me with the form(?: .+)?$/,
      /^go to the form$/,
      /^take me to the form$/,
      /^what is on this page\??$/,
      /^help me find something$/,
      /^take me to .+$/,
      /^show me .+$/,
      /^open .+$/,
    ].some(function(pattern) {
      return pattern.test(normalized);
    });
  }

  function rememberFromConversation(message) {
    const lowered = message.toLowerCase();

    if (lowered.includes("contact me by email")) {
      state.memory.preferredContact = "email";
    } else if (lowered.includes("call me") || lowered.includes("contact me by phone")) {
      state.memory.preferredContact = "phone";
    }

    if (/\b(voice mode|talk to me|speak to me|use voice|voice replies)\b/.test(lowered) && speechAvailable) {
      state.memory.voiceReplies = true;
      state.memory.continuousVoice = /\b(continuous|hands free|hands-free)\b/.test(lowered);
      refreshVoiceUi();
    }

    const serviceInterest = message.match(/\b(?:interested in|looking for|need help with)\s+([a-z0-9][a-z0-9\s&'-]{2,80})/i);
    if (serviceInterest) {
      state.memory.interest = serviceInterest[1].trim().replace(/\s+/g, " ");
    }
  }

  function handleAction(action) {
    if (!action || !action.type) {
      return;
    }

    if (action.type === "navigate" && action.url) {
      rememberPendingNavigation(action);
      setStatus("Taking you there");
      window.location.href = action.url;
      return;
    }

    if (action.type === "scroll_to_form") {
      const form = document.querySelectorAll("form")[action.formIndex || 0];
      if (form) {
        setStatus("Taking you to the form");
        form.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      return;
    }

    if (action.type === "fill_form") {
      const form = document.querySelectorAll("form")[action.formIndex || 0];
      if (!form) {
        return;
      }

      fillFormFields(form, action.fields || {}, action.profile || state.profile || {});

      setStatus("Filling in what I know");
      form.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }

    if (action.type === "clear_profile") {
      clearSavedProfile();
      setStatus("Saved details cleared");
    }
  }

  function clearSavedProfile() {
    state.profile = {};
    state.memory = Object.assign({}, state.memory, {
      pendingPromptStarter: "",
      preferredContact: "",
      visitorName: "",
      interest: "",
    });

    Object.keys(state.memory).forEach(function(key) {
      if (state.memory[key] === "") {
        delete state.memory[key];
      }
    });

    saveState();
  }

  function fillFormFields(form, fieldMap, profile) {
    Object.keys(fieldMap).forEach(function(key) {
      const value = fieldMap[key];
      const field = form.querySelector('[name="' + cssEscape(key) + '"]')
        || form.querySelector("#" + cssEscape(key));

      if (field && typeof value === "string") {
        applyValueToField(field, value);
      }
    });

    Array.from(form.querySelectorAll("input, textarea, select")).forEach(function(field) {
      if (shouldIgnoreField(field) || field.value) {
        return;
      }

      const inferredValue = inferProfileValueForField(field, profile);
      if (typeof inferredValue === "string" && inferredValue.trim() !== "") {
        applyValueToField(field, inferredValue.trim());
      }
    });
  }

  function inferProfileValueForField(field, profile) {
    const label = (getFieldLabel(field) || "").toLowerCase();
    const name = (field.name || field.id || "").toLowerCase();
    const placeholder = (field.placeholder || "").toLowerCase();
    const type = (field.type || field.tagName || "").toLowerCase();
    const haystack = [label, name, placeholder, type].join(" ");
    const aliases = [label, name, placeholder]
      .map(normalizeFormFieldProfileKey)
      .filter(Boolean);
    const formFields = profile && profile.formFields && typeof profile.formFields === "object"
      ? profile.formFields
      : {};

    for (const alias of aliases) {
      if (typeof formFields[alias] === "string" && formFields[alias].trim() !== "") {
        return formFields[alias].trim();
      }
    }

    if ((haystack.includes("first name") || name === "firstname" || name === "first_name") && profile.firstName) {
      return profile.firstName;
    }

    if ((haystack.includes("last name") || name === "lastname" || name === "last_name") && profile.lastName) {
      return profile.lastName;
    }

    if (haystack.includes("full name") || haystack.includes("your name") || /(^|\s)name(\s|$)/.test(haystack)) {
      const bestName = resolveBestNameValue(profile);
      if (bestName) {
        return bestName;
      }
    }

    if ((haystack.includes("email") || type === "email") && profile.email) {
      return profile.email;
    }

    if ((haystack.includes("phone") || haystack.includes("mobile") || haystack.includes("tel") || type === "tel") && profile.phone) {
      return profile.phone;
    }

    if ((haystack.includes("company") || haystack.includes("organisation") || haystack.includes("organization")) && profile.company) {
      return profile.company;
    }

    if (haystack.includes("subject") && profile.subject) {
      return profile.subject;
    }

    if ((haystack.includes("message") || haystack.includes("comment") || haystack.includes("enquiry") || haystack.includes("inquiry")) && profile.message) {
      return profile.message;
    }

    return null;
  }

  function resolveBestNameValue(profile) {
    if (!profile || typeof profile !== "object") {
      return null;
    }

    if (typeof profile.name === "string" && profile.name.trim() !== "") {
      return profile.name.trim();
    }

    const first = typeof profile.firstName === "string" ? profile.firstName.trim() : "";
    const last = typeof profile.lastName === "string" ? profile.lastName.trim() : "";
    const combined = [first, last].filter(Boolean).join(" ").trim();

    if (combined) {
      return combined;
    }

    return first || last || null;
  }

  function applyValueToField(field, value) {
    if (field.tagName === "SELECT") {
      const option = Array.from(field.options).find(function(item) {
        return String(item.text || "").toLowerCase() === String(value).toLowerCase()
          || String(item.value || "").toLowerCase() === String(value).toLowerCase();
      });

      field.value = option ? option.value : value;
    } else {
      field.value = value;
    }

    field.dispatchEvent(new Event("input", { bubbles: true }));
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function toggleVoiceInput() {
    const SpeechRecognition = getSpeechRecognitionCtor();
    if (!SpeechRecognition) {
      appendAssistantMessage("Voice input is not available in this browser yet.");
      return;
    }

    if (recognition) {
      recognition.stop();
      recognition = null;
      micButtonEl.classList.remove("is-recording");
      refreshMicUi();
      return;
    }

    recognition = new SpeechRecognition();
    recognition.lang = document.documentElement.lang || "en-US";
    recognition.interimResults = true;
    recognition.continuous = isContinuousVoiceMode();
    recognitionTranscript = "";

    recognition.onstart = function() {
      micButtonEl.classList.add("is-recording");
      micButtonEl.setAttribute("aria-label", "Voice input is listening");
      shell.classList.add("is-listening");
      refreshMicUi();
      setStatus(isContinuousVoiceMode() ? "Listening in voice mode..." : "Listening...");
    };

    recognition.onresult = function(event) {
      let interimTranscript = "";
      let finalTranscript = "";

      Array.from(event.results).forEach(function(result) {
        const piece = result[0] && result[0].transcript ? result[0].transcript : "";
        if (result.isFinal) {
          finalTranscript += piece + " ";
        } else {
          interimTranscript += piece + " ";
        }
      });

      recognitionTranscript = (recognitionTranscript + " " + finalTranscript).trim();
      textareaEl.value = (recognitionTranscript + " " + interimTranscript).trim();
    };

    recognition.onerror = function(event) {
      micButtonEl.classList.remove("is-recording");
      micButtonEl.setAttribute("aria-label", "Use voice input");
      shell.classList.remove("is-listening");
      recognition = null;
      refreshMicUi();

      const voiceError = describeVoiceError(event);

      if (isContinuousVoiceMode() && shell.classList.contains("is-open")) {
        if (voiceError.retryable) {
          setStatus(voiceError.statusText || "Voice mode armed");
          appendAssistantMessage(voiceError.message);
          scheduleVoiceRestart();
          return;
        }

        state.memory.continuousVoice = false;
        saveState();
        refreshVoiceUi();
        setStatus(voiceError.statusText || "Voice input unavailable");
        appendAssistantMessage(voiceError.message);
        return;
      }

      setStatus(voiceError.statusText || "Voice input unavailable");
      appendAssistantMessage(voiceError.message);
    };

    recognition.onend = function() {
      micButtonEl.classList.remove("is-recording");
      micButtonEl.setAttribute("aria-label", "Use voice input");
      shell.classList.remove("is-listening");
      recognition = null;
      refreshMicUi();

      if (isContinuousVoiceMode() && textareaEl.value.trim()) {
        sendCurrentMessage();
        return;
      }

      if (isContinuousVoiceMode() && !textareaEl.value.trim()) {
        setStatus("Voice mode armed");
        scheduleVoiceRestart();
        return;
      }

      setStatus("Neural channel open");

      textareaEl.focus();
    };

    recognition.start();
  }

  function toggleVoiceReplies() {
    if (!speechAvailable) {
      appendAssistantMessage("Spoken replies are not available in this browser yet.");
      return;
    }

    if (!state.memory.voiceReplies) {
      state.memory.voiceReplies = true;
      state.memory.continuousVoice = false;
    } else if (!state.memory.continuousVoice) {
      state.memory.voiceReplies = true;
      state.memory.continuousVoice = true;
    } else {
      state.memory.voiceReplies = false;
      state.memory.continuousVoice = false;
      stopSpeaking();
    }

    saveState();
    refreshVoiceUi();
    refreshMicUi();
    setStatus(
      state.memory.continuousVoice
        ? "Continuous voice on"
        : (state.memory.voiceReplies ? "Voice replies on" : "Neural channel open")
    );
  }

  function refreshVoiceUi() {
    if (!voiceToggleEl) {
      return;
    }

    const enabled = !!state.memory.voiceReplies;
    const continuous = !!state.memory.continuousVoice;
    voiceToggleEl.classList.toggle("is-active", enabled);
    voiceToggleEl.classList.toggle("is-continuous", enabled && continuous);
    voiceToggleEl.setAttribute("aria-pressed", enabled ? "true" : "false");

    const label = voiceToggleEl.querySelector(".interactive-ai-voice-toggle-label");
    if (label) {
      label.textContent = enabled
        ? (continuous ? "Continuous voice on" : "Voice replies on")
        : "Voice replies off";
    }

    if (!speechAvailable) {
      voiceToggleEl.classList.add("is-disabled");
      voiceToggleEl.setAttribute("disabled", "disabled");
    } else {
      voiceToggleEl.classList.remove("is-disabled");
      voiceToggleEl.removeAttribute("disabled");
    }
  }

  function refreshMicUi() {
    if (!micButtonEl) {
      return;
    }

    const recognitionAvailable = !!getSpeechRecognitionCtor();
    const armed = recognitionAvailable && isContinuousVoiceMode();
    const ready = recognitionAvailable && !recognition;

    micButtonEl.classList.toggle("is-ready", ready);
    micButtonEl.classList.toggle("is-armed", armed);

    if (recognition) {
      micButtonEl.setAttribute("aria-label", "Voice input is listening");
    } else if (armed) {
      micButtonEl.setAttribute("aria-label", "Continuous voice mode is ready");
    } else {
      micButtonEl.setAttribute("aria-label", "Use voice input");
    }
  }

  function scheduleVoiceRestart() {
    if (voiceRestartTimer) {
      window.clearTimeout(voiceRestartTimer);
    }

    voiceRestartTimer = window.setTimeout(function() {
      voiceRestartTimer = null;
      if (isContinuousVoiceMode() && !recognition && shell.classList.contains("is-open")) {
        toggleVoiceInput();
      }
    }, 350);
  }

  function shouldSpeakReplies() {
    return speechAvailable && !!state.memory.voiceReplies;
  }

  function isContinuousVoiceMode() {
    return !!state.memory.voiceReplies && !!state.memory.continuousVoice;
  }

  function speakReply(content) {
    if (!shouldSpeakReplies() || !content) {
      return;
    }

    stopSpeaking();

    const utterance = new SpeechSynthesisUtterance(content);
    utterance.rate = 0.98;
    utterance.pitch = 1.02;
    utterance.volume = 1;
    utterance.lang = getPreferredLang();

    if (selectedVoice) {
      utterance.voice = selectedVoice;
      utterance.lang = selectedVoice.lang || utterance.lang;
    }

    utterance.onstart = function() {
      shell.classList.add("is-speaking");
      setStatus("Speaking...");
    };

    utterance.onend = function() {
      shell.classList.remove("is-speaking");
      if (isContinuousVoiceMode()) {
        startContinuousVoiceLoop();
      } else {
        setStatus("Neural channel open");
      }
    };

    utterance.onerror = function() {
      shell.classList.remove("is-speaking");
      setStatus("Neural channel open");
    };

    window.speechSynthesis.speak(utterance);
  }

  function stopSpeaking() {
    if (speechAvailable && window.speechSynthesis.speaking) {
      window.speechSynthesis.cancel();
    }
    shell.classList.remove("is-speaking");
  }

  function startContinuousVoiceLoop() {
    state.memory.voiceReplies = true;
    state.memory.continuousVoice = true;
    saveState();
    refreshVoiceUi();
    refreshMicUi();
    setStatus("Voice mode armed");
    toggleVoiceInput();
  }

  function getSpeechRecognitionCtor() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
  }

  function describeVoiceError(event) {
    const error = String(event && event.error ? event.error : "").toLowerCase();

    switch (error) {
      case "audio-capture":
        return {
          message: "I could not hear a microphone on this device. Please connect a mic and try again.",
          statusText: "No microphone detected",
          retryable: false,
        };
      case "not-allowed":
      case "service-not-allowed":
        return {
          message: "Microphone access is blocked in this browser right now. If you allow microphone permission, voice input should work.",
          statusText: "Microphone access blocked",
          retryable: false,
        };
      case "no-speech":
        return {
          message: "I was listening, but I did not catch any speech. You can try again when you are ready.",
          statusText: "No speech detected",
          retryable: true,
        };
      case "network":
        return {
          message: "Voice recognition hit a network problem just now. Please try again in a moment.",
          statusText: "Voice network issue",
          retryable: true,
        };
      case "aborted":
        return {
          message: "Voice input was stopped before it finished listening.",
          statusText: "Voice input stopped",
          retryable: true,
        };
      case "bad-grammar":
      case "language-not-supported":
        return {
          message: "This browser could not start voice input for the current language settings.",
          statusText: "Voice language issue",
          retryable: false,
        };
      default:
        return {
          message: error
            ? "Voice input ran into a browser error: " + error + "."
            : "Voice input ran into an unexpected browser error.",
          statusText: "Voice input error",
          retryable: true,
        };
    }
  }

  function pickPreferredVoice(voices) {
    const candidates = Array.isArray(voices) ? voices.slice() : [];
    if (!candidates.length) {
      return null;
    }

    const preferredLang = getPreferredLang().toLowerCase();

    return candidates
      .map(function(voice) {
        const name = String(voice.name || "").toLowerCase();
        const lang = String(voice.lang || "").toLowerCase();
        let score = 0;

        if (lang === preferredLang) {
          score += 12;
        } else if (lang.startsWith(preferredLang.split("-")[0])) {
          score += 8;
        }

        if (voice.localService) {
          score += 4;
        }

        ["natural", "premium", "enhanced", "neural", "aria", "jenny", "samantha", "serena", "ava", "daniel", "google uk english female"].forEach(function(marker) {
          if (name.includes(marker)) {
            score += 5;
          }
        });

        return { voice: voice, score: score };
      })
      .sort(function(a, b) {
        return b.score - a.score;
      })[0].voice;
  }

  function getPreferredLang() {
    return document.documentElement.lang || navigator.language || "en-US";
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
      return window.CSS.escape(value);
    }

    return String(value).replace(/[^a-zA-Z0-9_-]/g, "\\$&");
  }

  function normalizeUiOption(value) {
    return String(value || "")
      .toLowerCase()
      .replace(/[^\p{L}\p{N}\s]+/gu, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function isPromptStarterSuggestion(value) {
    const text = String(value || "").trim();
    return /\.\.\.$/.test(text);
  }

  function applyPendingPromptStarter(message) {
    const starter = String(state.memory.pendingPromptStarter || "").trim();
    if (!starter) {
      return message;
    }

    const normalizedMessage = normalizeUiOption(message);
    const normalizedStarter = normalizeUiOption(starter);

    if (normalizedMessage.startsWith(normalizedStarter)) {
      return message;
    }

    return (starter + " " + message).trim();
  }

  function setStatus(text) {
    if (statusLabelEl && text !== lastStatusText) {
      lastStatusText = text;
      statusLabelEl.textContent = text;
    }
  }

  function getThinkingStatus(message) {
    const normalized = String(message || "").toLowerCase();

    if (/\b(weather|time|date)\b/.test(normalized)) {
      return "Pulling in a quick live check...";
    }

    if (/\b(voice|speak|audio)\b/.test(normalized)) {
      return "Warming up the voice channel...";
    }

    if (/\b(form|contact|submit|apply|book)\b/.test(normalized)) {
      return "Preparing the next step...";
    }

    if (/\b(page|site|section|article|news|service|team|event|location)\b/.test(normalized)) {
      return "Mapping the site...";
    }

    return "Synthesizing a reply...";
  }

  function setMood(mode) {
    shell.classList.remove("is-thinking", "is-listening");

    if (mode === "thinking") {
      shell.classList.add("is-thinking");
      setStatus("Synthesizing a reply...");
      return;
    }

    if (mode === "welcoming") {
      setStatus("Neural channel open");
      return;
    }

    setStatus("Neural channel open");
  }
})();

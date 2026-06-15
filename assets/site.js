(function () {
  const players = new Map();
  const saveTimers = new Map();

  function youtubeFrames() {
    return Array.from(document.querySelectorAll("[data-video-player]"));
  }

  function html5Videos() {
    return Array.from(document.querySelectorAll("[data-html5-video]"));
  }

  function trackedVideos() {
    return youtubeFrames().concat(html5Videos());
  }

  function setProgress(moduleId, percent, completed) {
    const rounded = Math.max(0, Math.min(100, Math.round(percent || 0)));
    const label = document.querySelector(`[data-video-progress-label="${moduleId}"]`);
    const fill = document.querySelector(`[data-video-progress-fill="${moduleId}"]`);
    if (label) {
      label.textContent = `${rounded}% watched${completed ? " - completed" : ""}`;
    }
    if (fill) {
      fill.style.width = `${rounded}%`;
    }
  }

  function storageKey(moduleId) {
    return `sic-video-progress-${moduleId}`;
  }

  function readLocalProgress(moduleId) {
    try {
      const raw = window.localStorage.getItem(storageKey(moduleId));
      return raw ? JSON.parse(raw) : null;
    } catch (error) {
      return null;
    }
  }

  function writeLocalProgress(payload) {
    if (!payload.moduleId || payload.duration <= 0) {
      return;
    }

    try {
      window.localStorage.setItem(storageKey(payload.moduleId), JSON.stringify({
        current: payload.current,
        duration: payload.duration,
        percent: payload.percent,
        completed: payload.completed,
        updatedAt: Date.now()
      }));
    } catch (error) {
      // Local storage can be blocked in private browsing; server progress remains the main source.
    }
  }

  function encodedProgressBody(payload) {
    const params = new URLSearchParams();
    params.set("csrf_token", payload.csrfToken);
    params.set("module_id", payload.moduleId);
    params.set("current_seconds", String(payload.current));
    params.set("duration_seconds", String(payload.duration));
    params.set("percent_watched", String(payload.percent));
    params.set("is_completed", payload.completed ? "1" : "0");
    return params.toString();
  }

  function readDuration(player) {
    if (player && typeof player.getDuration === "function") {
      return player.getDuration() || 0;
    }
    return player && Number.isFinite(player.duration) ? player.duration : 0;
  }

  function readCurrentTime(player) {
    if (player && typeof player.getCurrentTime === "function") {
      return player.getCurrentTime() || 0;
    }
    return player && Number.isFinite(player.currentTime) ? player.currentTime : 0;
  }

  function progressPayload(element, player, completed) {
    const duration = Math.max(0, Math.floor(readDuration(player)));
    const current = completed && duration > 0
      ? duration
      : Math.max(0, Math.floor(readCurrentTime(player)));
    const percent = duration > 0 ? Math.min(100, (current / duration) * 100) : 0;

    return {
      moduleId: element.dataset.moduleId,
      csrfToken: element.dataset.csrfToken,
      saveUrl: element.dataset.saveUrl,
      current,
      duration,
      percent,
      completed: completed || percent >= 90
    };
  }

  function saveProgress(element, player, completed, immediate) {
    if (!element || !player) {
      return Promise.resolve();
    }

    const payload = progressPayload(element, player, completed);
    if (!payload.moduleId || !payload.csrfToken || !payload.saveUrl || payload.duration <= 0) {
      return Promise.resolve();
    }

    setProgress(payload.moduleId, payload.percent, payload.completed);
    writeLocalProgress(payload);

    if (immediate && navigator.sendBeacon) {
      const body = encodedProgressBody(payload);
      const sent = navigator.sendBeacon(
        payload.saveUrl,
        new Blob([body], { type: "application/x-www-form-urlencoded" })
      );
      if (sent) {
        return Promise.resolve();
      }
    }

    const form = new FormData();
    form.append("csrf_token", payload.csrfToken);
    form.append("module_id", payload.moduleId);
    form.append("current_seconds", String(payload.current));
    form.append("duration_seconds", String(payload.duration));
    form.append("percent_watched", String(payload.percent));
    form.append("is_completed", payload.completed ? "1" : "0");

    return fetch(payload.saveUrl, {
      method: "POST",
      body: form,
      credentials: "same-origin",
      keepalive: !!immediate
    }).catch(() => {});
  }

  function startSaving(element, player) {
    stopSaving(element.id);
    saveTimers.set(element.id, window.setInterval(() => {
      saveProgress(element, player, false);
    }, 4000));
  }

  function stopSaving(elementId) {
    if (saveTimers.has(elementId)) {
      window.clearInterval(saveTimers.get(elementId));
      saveTimers.delete(elementId);
    }
  }

  function saveAll(immediate) {
    const saves = [];
    trackedVideos().forEach(element => {
      const player = players.get(element.id) || element;
      if (player) {
        saves.push(saveProgress(element, player, false, immediate));
      }
    });
    return Promise.all(saves);
  }

  function resumeHtml5Video(video) {
    const savedSeconds = parseInt(video.dataset.resumeSeconds || "0", 10);
    const localProgress = readLocalProgress(video.dataset.moduleId);
    const localSeconds = localProgress && localProgress.current ? parseInt(localProgress.current, 10) : 0;
    const localPercent = localProgress && localProgress.percent ? Number(localProgress.percent) : 0;
    const resumeSeconds = Math.max(savedSeconds, localSeconds);

    if (localPercent > 0) {
      setProgress(video.dataset.moduleId, localPercent, !!localProgress.completed);
    }

    if (resumeSeconds <= 0) {
      return;
    }

    const seek = () => {
      if (Number.isFinite(video.duration) && video.duration > resumeSeconds) {
        video.currentTime = resumeSeconds;
      }
    };

    if (video.readyState >= 1) {
      seek();
    } else {
      video.addEventListener("loadedmetadata", seek, { once: true });
    }
  }

  function initHtml5Videos() {
    html5Videos().forEach(video => {
      if (!video.id || players.has(video.id)) {
        return;
      }

      players.set(video.id, video);
      resumeHtml5Video(video);

      video.addEventListener("play", () => startSaving(video, video));
      video.addEventListener("pause", () => {
        stopSaving(video.id);
        saveProgress(video, video, false);
      });
      video.addEventListener("ended", () => {
        stopSaving(video.id);
        saveProgress(video, video, true);
      });
      video.addEventListener("timeupdate", () => {
        if (video.duration > 0) {
          const percent = Math.min(100, (video.currentTime / video.duration) * 100);
          const completed = video.currentTime / video.duration >= 0.9;
          setProgress(video.dataset.moduleId, percent, completed);
          writeLocalProgress({
            moduleId: video.dataset.moduleId,
            current: Math.max(0, Math.floor(video.currentTime || 0)),
            duration: Math.max(0, Math.floor(video.duration || 0)),
            percent,
            completed
          });
        }
      });
    });
  }

  function initYoutubePlayers() {
    if (!window.YT || !window.YT.Player) {
      return;
    }

    youtubeFrames().forEach(frame => {
      if (!frame.id || players.has(frame.id)) {
        return;
      }

      const player = new window.YT.Player(frame.id, {
        events: {
          onReady: event => {
            players.set(frame.id, event.target);
            const resumeSeconds = parseInt(frame.dataset.resumeSeconds || "0", 10);
            if (resumeSeconds > 0 && typeof event.target.seekTo === "function") {
              window.setTimeout(() => {
                try {
                  event.target.seekTo(resumeSeconds, true);
                } catch (error) {
                  // YouTube can reject seeks before metadata is ready; the start param remains as fallback.
                }
              }, 250);
            }
          },
          onStateChange: event => {
            const state = event.data;
            if (state === window.YT.PlayerState.PLAYING) {
              startSaving(frame, event.target);
            } else if (state === window.YT.PlayerState.PAUSED) {
              stopSaving(frame.id);
              saveProgress(frame, event.target, false);
            } else if (state === window.YT.PlayerState.ENDED) {
              stopSaving(frame.id);
              saveProgress(frame, event.target, true);
            }
          }
        }
      });
      players.set(frame.id, player);
    });
  }

  initHtml5Videos();

  if (youtubeFrames().length > 0) {
    const script = document.createElement("script");
    script.src = "https://www.youtube.com/iframe_api";
    document.head.appendChild(script);
    window.onYouTubeIframeAPIReady = initYoutubePlayers;
  }

  document.addEventListener("click", event => {
    const link = event.target.closest ? event.target.closest("a[href]") : null;
    if (!link) {
      return;
    }

    const target = link.getAttribute("target");
    const href = link.getAttribute("href") || "";
    if (target && target !== "_self") {
      return;
    }
    if (href.startsWith("#") || href.startsWith("javascript:") || href.startsWith("mailto:")) {
      return;
    }

    saveAll(true);
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      saveAll(true);
    }
  });

  window.addEventListener("pagehide", () => {
    saveAll(true);
  });

  window.addEventListener("beforeunload", () => {
    saveAll(true);
  });
})();

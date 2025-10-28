'use strict';

var DEFAULT_TTS_VOICE = 'af_heart';
var SPEAK_ICON = '<img src="images/speaker.svg" alt="" class="speak-icon">';

var ttsEnabled = typeof window !== 'undefined'
    && typeof window.fetch === 'function'
    && typeof window.Audio !== 'undefined';

var activeSpeakButton = null;
var activeTtsControllers = [];
var audioContext = null;
var speakerGainNode = null;
var activeBufferSource = null;
var audioQueue = [];
var isAudioContextPlaying = false;
var activeSpeakMetadata = null;
var pendingSpeakFinish = false;
var activeSpeakSessionId = null;
var speakSessionCounter = 0;
var BUFFER_FADE_SECONDS = 0.02;

function addSpeakButton(messageElement, rawMessageContent) {
    if (!ttsEnabled) {
        return;
    }

    var plain = (rawMessageContent || '').replace(/\s+/g, ' ').trim();
    if (!plain) {
        return;
    }

    var speakButton = $('<button type="button" class="copy-chat-button speak-chat-button" title="Play audio for this reply" aria-label="Play audio for this reply"><svg xmlns="http://www.w3.org/2000/svg" version="1.0" width="202.000000pt" height="174.000000pt" viewBox="0 0 202.000000 174.000000" preserveAspectRatio="xMidYMid meet"><metadata>Created by potrace 1.10, written by Peter Selinger 2001-2011</metadata><g transform="translate(0.000000,174.000000) scale(0.100000,-0.100000)" fill="#a5803b" stroke="none"><path d="M875 1620 c-16 -11 -110 -103 -209 -205 l-178 -185 -152 0 c-167 0 -187 -6 -223 -59 -23 -33 -23 -40 -23 -303 0 -292 2 -305 56 -347 25 -19 40 -21 184 -21 l156 0 184 -191 c101 -104 196 -197 211 -205 59 -30 113 -12 147 49 16 29 17 86 20 676 2 442 -1 661 -8 698 -21 98 -94 140 -165 93z m45 -757 l0 -628 -198 198 -197 197 -158 0 -157 0 0 235 0 235 158 0 157 0 195 195 c107 107 196 195 197 195 2 0 3 -282 3 -627z"/><path d="M1564 1552 c-19 -12 -35 -52 -28 -65 4 -6 33 -41 65 -77 206 -236 267 -526 168 -808 -38 -108 -84 -185 -167 -281 -68 -77 -80 -113 -46 -138 47 -34 96 -2 194 129 73 97 131 215 161 328 33 121 33 329 0 450 -30 113 -88 231 -161 328 -92 123 -146 161 -186 134z"/><path d="M1384 1382 c-6 -4 -16 -16 -22 -28 -13 -24 -6 -37 60 -119 64 -80 95 -140 118 -231 25 -98 25 -180 0 -278 -23 -91 -54 -151 -118 -231 -26 -32 -52 -66 -58 -77 -25 -43 17 -87 70 -74 55 14 171 182 218 316 24 68 35 250 19 333 -22 118 -89 249 -176 343 -50 53 -81 66 -111 46z"/><path d="M1198 1208 c-34 -27 -30 -53 17 -113 66 -86 88 -151 83 -250 -5 -89 -24 -137 -89 -219 -44 -54 -46 -62 -23 -94 27 -38 70 -30 120 25 127 138 157 339 74 506 -59 121 -135 181 -182 145z"/></g></svg></button>');
    speakButton.css('margin-left', '10px');
    setSpeakButtonLabel(speakButton, 'Play Audio');

    var statusSpan = $('<span class="speak-status" aria-live="polite"></span>');
    speakButton.data('speakStatus', statusSpan);

    var controlsWrapper = ensureReplyControls(messageElement);
    controlsWrapper.append(speakButton, statusSpan);

    speakButton.on('click', function (event) {
        event.preventDefault();
        if (!ttsEnabled) {
            return;
        }

        var sameButtonActive = activeSpeakButton && activeSpeakButton[0] === speakButton[0];

        stopActiveAudio();
        if (activeSpeakButton) {
            resetSpeakButton(activeSpeakButton);
            activeSpeakButton = null;
        }

        if (sameButtonActive) {
            return;
        }

        var status = speakButton.data('speakStatus');
        if (status) {
            status.text('Preparing audio…');
        }

        activeSpeakButton = speakButton;
        var sessionId = ++speakSessionCounter;
        activeSpeakSessionId = sessionId;
        setSpeakButtonPlaying(speakButton);
        activeTtsControllers = [];
        activeSpeakMetadata = {
            button: speakButton,
            status: status,
            sessionId: sessionId
        };
        pendingSpeakFinish = false;

        var sanitized = plain.replace(/[*_`]/g, '');
        var chunks = chunkTextForTts(sanitized);
        var chunkFetches = [];

        function ensureFetch(index) {
            if (index >= chunks.length) {
                return null;
            }
            if (!chunkFetches[index]) {
                chunkFetches[index] = fetchAudioChunk(chunks[index]);
            }
            return chunkFetches[index];
        }

        ensureFetch(0);
        if (chunks.length > 1) {
            ensureFetch(1);
        }

        var currentIndex = 0;

        function playChunk(index) {
            if (sessionId !== activeSpeakSessionId) {
                return null;
            }

            if (index + 1 < chunks.length) {
                ensureFetch(index + 1);
            }

            var fetchPromise = ensureFetch(index);
            if (!fetchPromise) {
                if (sessionId === activeSpeakSessionId) {
                    pendingSpeakFinish = true;
                    checkPlaybackCompletion(sessionId);
                }
                return null;
            }

            fetchPromise
                .then(function (buffer) {
                    if (sessionId !== activeSpeakSessionId) {
                        return null;
                    }
                    if (!buffer) {
                        return Promise.resolve();
                    }
                    queueAudioBuffer(buffer, sessionId);
                    return Promise.resolve();
                })
                .then(function () {
                    if (sessionId !== activeSpeakSessionId) {
                        return null;
                    }
                    currentIndex += 1;
                    if (currentIndex < chunks.length) {
                        return playChunk(currentIndex);
                    }
                    pendingSpeakFinish = true;
                    checkPlaybackCompletion(sessionId);
                    return null;
                })
                .catch(function (err) {
                    if (sessionId !== activeSpeakSessionId) {
                        return;
                    }
                    console.error('Failed to fetch audio chunk', err);
                    finalizeSpeakSession(sessionId);
                });

            return null;
        }

        playChunk(0);
    });
}

function stopActiveAudio() {
    activeSpeakSessionId = null;
    activeSpeakMetadata = null;
    activeTtsControllers.forEach(function (controller) {
        try {
            controller.abort();
        } catch (err) {
            console.warn('Abort controller failed', err);
        }
    });
    activeTtsControllers = [];

    pendingSpeakFinish = false;
    activeSpeakSessionId = null;

    if (activeBufferSource) {
        try {
            activeBufferSource.stop();
        } catch (err) {
            console.warn('Unable to stop buffer source', err);
        }
    }
    activeBufferSource = null;

    audioQueue = [];
    isAudioContextPlaying = false;

    if (audioContext) {
        try {
            audioContext.close();
        } catch (err) {
            console.warn('Audio context close failed', err);
        }
        audioContext = null;
        speakerGainNode = null;
    }
}

function resetSpeakButton(button) {
    if (!button) {
        return;
    }
    setSpeakButtonLabel(button, 'Play Audio');
    button.removeClass('is-playing');
    button.prop('disabled', false);
    var status = button.data('speakStatus');
    if (status) {
        status.text('');
    }
}

function setSpeakButtonPlaying(button) {
    if (!button) {
        return;
    }
    button.addClass('is-playing');
    setSpeakButtonLabel(button, 'Stop');
}

function setSpeakButtonLabel(button, text) {
    if (!button) {
        return;
    }
    button.html('<span>' + text + '</span>' + SPEAK_ICON);
}

function chunkTextForTts(text) {
    var tiers = [
        { max: 100, min: 50 },
        { max: 175, min: 60 },
        { max: 250, min: 120 },
        { max: 375, min: 180 },
        { max: 500, min: 200 }
    ];

    var remaining = text.trim();
    var chunks = [];
    var tierIndex = 0;

    while (remaining.length > 0) {
        var activeTier = tiers[Math.min(tierIndex, tiers.length - 1)];
        var maxLen = activeTier.max;
        var minLen = activeTier.min;

        if (remaining.length <= maxLen) {
            chunks.push(remaining);
            break;
        }

        var breakInfo = findNaturalBreakPoint(remaining, minLen, maxLen);
        var chunkText = remaining.slice(0, breakInfo.length).trim();

        if (!chunkText) {
            chunkText = remaining.slice(0, maxLen).trim();
            breakInfo.length = chunkText.length;
        }

        if (chunkText) {
            chunks.push(chunkText);
        }

        remaining = remaining.slice(Math.min(remaining.length, breakInfo.length)).trim();

        if (tierIndex < tiers.length - 1) {
            tierIndex += 1;
        }
    }

    if (!chunks.length) {
        chunks.push(text);
    }

    return chunks;
}

function fetchAudioChunk(text) {
    var controller = new AbortController();
    activeTtsControllers.push(controller);

    return fetch('tts_proxy.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            text: text,
            voice: DEFAULT_TTS_VOICE
        }),
        signal: controller.signal
    }).then(function (response) {
        if (!response.ok) {
            throw new Error('TTS request failed with status ' + response.status);
        }
        var contentType = response.headers.get('Content-Type') || '';
        if (contentType.indexOf('audio') === -1) {
            throw new Error('Unexpected content-type ' + contentType);
        }
        return response.arrayBuffer();
    }).finally(function () {
        var index = activeTtsControllers.indexOf(controller);
        if (index !== -1) {
            activeTtsControllers.splice(index, 1);
        }
    }).then(function (arrayBuffer) {
        ensureAudioContext();
        return new Promise(function (resolve, reject) {
            audioContext.decodeAudioData(arrayBuffer, function (buffer) {
                try {
                    applyFadeEnvelope(buffer);
                } catch (envErr) {
                    console.warn('Unable to apply fade envelope', envErr);
                }
                resolve(buffer);
            }, reject);
        });
    });
}

function findNaturalBreakPoint(text, minLen, maxLen) {
    var punctuationChars = ',.!?;:';
    var safetyWindow = 60;
    var effectiveMax = Math.min(text.length, maxLen);
    var searchLimit = Math.min(text.length, maxLen + safetyWindow);
    var searchSlice = text.slice(0, searchLimit);
    var bestMatch = -1;
    var bestScore = -Infinity;
    var postMaxMatch = -1;
    var whitespaceFallback = -1;

    for (var i = 0; i < searchSlice.length; i++) {
        var char = searchSlice[i];
        var nextChar = searchSlice[i + 1] || '';
        var position = i + 1; // length including current char

        if (char === '\n' || char === '\r') {
            if (position >= minLen) {
                bestMatch = position;
                bestScore = 1000;
                break;
            }
            continue;
        }

        if (char === ' ') {
            whitespaceFallback = position;
        }

        if (punctuationChars.indexOf(char) !== -1) {
            var isSentenceEnding = (char === '.' || char === '?' || char === '!');
            var punctuationBonus = isSentenceEnding ? 75 : 40;
            if (nextChar === ' ') {
                punctuationBonus += 5;
            }

            if (position >= minLen && position <= effectiveMax) {
                var score = punctuationBonus + (position / effectiveMax) * 25;
                if (score > bestScore) {
                    bestScore = score;
                    bestMatch = position;
                }
            } else if (position > effectiveMax && postMaxMatch === -1) {
                postMaxMatch = position;
            }
        }
    }

    if (bestMatch !== -1) {
        return { length: bestMatch };
    }

    if (postMaxMatch !== -1 && postMaxMatch <= searchLimit) {
        return { length: postMaxMatch };
    }

    if (whitespaceFallback !== -1 && whitespaceFallback >= minLen) {
        return { length: whitespaceFallback };
    }

    return { length: effectiveMax };
}

function ensureAudioContext() {
    if (!window.AudioContext && !window.webkitAudioContext) {
        throw new Error('Web Audio API not supported in this browser');
    }
    if (!audioContext) {
        var Ctor = window.AudioContext || window.webkitAudioContext;
        audioContext = new Ctor();
    }
    if (!speakerGainNode && audioContext) {
        speakerGainNode = audioContext.createGain();
        speakerGainNode.gain.setValueAtTime(0, audioContext.currentTime);
        speakerGainNode.connect(audioContext.destination);
    }
    if (audioContext.state === 'suspended') {
        audioContext.resume().catch(function (err) {
            console.warn('Unable to resume audio context', err);
        });
    }
    return audioContext;
}

function queueAudioBuffer(buffer, sessionId) {
    if (sessionId && sessionId !== activeSpeakSessionId) {
        return;
    }
    audioQueue.push(buffer);
    if (!isAudioContextPlaying) {
        playNextBuffer(sessionId || activeSpeakSessionId);
    }
}

function playNextBuffer(sessionId) {
    if (sessionId && sessionId !== activeSpeakSessionId) {
        isAudioContextPlaying = false;
        return;
    }
    if (!audioQueue.length || !audioContext) {
        isAudioContextPlaying = false;
        checkPlaybackCompletion(sessionId || activeSpeakSessionId);
        return;
    }

    var wasPlaying = isAudioContextPlaying;
    isAudioContextPlaying = true;
    var buffer = audioQueue.shift();
    var source = audioContext.createBufferSource();
    activeBufferSource = source;
    source.buffer = buffer;

    var destination = speakerGainNode ? speakerGainNode : audioContext.destination;
    try {
        source.connect(destination);
    } catch (connectErr) {
        console.error('Audio buffer connect failed:', connectErr);
        activeBufferSource = null;
        isAudioContextPlaying = false;
        checkPlaybackCompletion();
        return;
    }

    source.onended = function () {
        activeBufferSource = null;
        playNextBuffer(sessionId || activeSpeakSessionId);
    };

    if (!wasPlaying && speakerGainNode) {
        var now = audioContext.currentTime;
        try {
            speakerGainNode.gain.cancelScheduledValues(now);
            speakerGainNode.gain.setValueAtTime(0, now);
            speakerGainNode.gain.linearRampToValueAtTime(1, now + 0.05);
        } catch (fadeErr) {
            console.warn('Gain ramp failed', fadeErr);
        }
    }

    try {
        source.start(0);
    } catch (err) {
        console.error('Audio buffer start failed:', err);
        activeBufferSource = null;
        isAudioContextPlaying = false;
        playNextBuffer(sessionId || activeSpeakSessionId);
    }
}

function applyFadeEnvelope(buffer) {
    if (!buffer) {
        return;
    }
    var sampleRate = buffer.sampleRate || 44100;
    var channels = buffer.numberOfChannels || 0;
    var fadeDuration = Math.min(BUFFER_FADE_SECONDS, buffer.duration ? buffer.duration / 4 : BUFFER_FADE_SECONDS);
    if (!channels || fadeDuration <= 0) {
        return;
    }
    var fadeSamples = Math.max(1, Math.floor(sampleRate * fadeDuration));
    for (var channel = 0; channel < channels; channel++) {
        var data = buffer.getChannelData(channel);
        if (!data || !data.length) {
            continue;
        }
        var length = data.length;
        var maxFadeSamples = Math.min(fadeSamples, Math.floor(length / 2));
        for (var i = 0; i < maxFadeSamples; i++) {
            var fadeInGain = i / maxFadeSamples;
            data[i] *= fadeInGain;
            var tailIndex = length - 1 - i;
            if (tailIndex <= i) {
                break;
            }
            var fadeOutGain = (maxFadeSamples - i - 1) / maxFadeSamples;
            if (fadeOutGain < 0) {
                fadeOutGain = 0;
            }
            data[tailIndex] *= fadeOutGain;
        }
    }
}

function checkPlaybackCompletion(sessionId) {
    if (sessionId && sessionId !== activeSpeakSessionId) {
        return;
    }
    if (pendingSpeakFinish && audioQueue.length === 0 && !isAudioContextPlaying) {
        finalizeSpeakSession(sessionId || activeSpeakSessionId);
    }
}

function finalizeSpeakSession(sessionId) {
    if (sessionId && sessionId !== activeSpeakSessionId) {
        return;
    }
    var meta = activeSpeakMetadata;
    if (sessionId && meta && meta.sessionId && meta.sessionId !== sessionId) {
        return;
    }
    activeSpeakMetadata = null;
    pendingSpeakFinish = false;

    stopActiveAudio();

    if (meta && meta.status) {
        meta.status.text('');
    }
    if (meta && meta.button) {
        resetSpeakButton(meta.button);
    }
    activeSpeakButton = null;
}

window.addSpeakButton = addSpeakButton;
window.stopActiveAudio = stopActiveAudio;

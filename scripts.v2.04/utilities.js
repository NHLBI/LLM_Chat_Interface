// utilities.js
function sanitizeString(str) {
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
}
function base64DecodeUnicode(str) {
    // Decode base64, then URI decode to handle Unicode characters
    return decodeURIComponent(Array.prototype.map.call(atob(str), function(c) {
        return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
    }).join(''));
}
function base64EncodeUnicode(str) {
    // Firstly, escape the string using encodeURIComponent to get the UTF-8 encoding of the character
    // Secondly, we convert the percent encodings into raw bytes, and finally to base64
    return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, (match, p1) => {
        return String.fromCharCode('0x' + p1);
    }));
}
function replaceNonAsciiCharacters(str) {
    str = str.replace(/[\u2018\u2019]/g, "'"); 
    str = str.replace(/[\u201C\u201D]/g, '"');
    str = str.replace(/\u2026/g, '...');
    return str;
}

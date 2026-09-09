const DREMART_WALLET_CACHE = 'dremart-wallet-pwa-v3';
const DREMART_WALLET_START = '/web3/create-wallet/';

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(DREMART_WALLET_CACHE)
      .then(function (cache) {
        return cache.add(DREMART_WALLET_START);
      })
      .catch(function () {
        return undefined;
      })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys
          .filter(function (key) {
            return key.indexOf('dremart-wallet-pwa-') === 0 && key !== DREMART_WALLET_CACHE;
          })
          .map(function (key) {
            return caches.delete(key);
          })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  if (event.request.mode !== 'navigate') return;

  event.respondWith(
    fetch(event.request).catch(function () {
      return caches.match(DREMART_WALLET_START);
    })
  );
});

import '@testing-library/jest-dom'

// jsdom does not always expose a global localStorage; provide a minimal
// in-memory implementation so components using it work under test.
if (typeof globalThis.localStorage === 'undefined') {
  const store = new Map<string, string>()
  const storage: Storage = {
    get length() { return store.size },
    clear: () => store.clear(),
    getItem: (k: string) => store.get(k) ?? null,
    key: (i: number) => Array.from(store.keys())[i] ?? null,
    removeItem: (k: string) => { store.delete(k) },
    setItem: (k: string, v: string) => { store.set(k, v) },
  }
  globalThis.localStorage = storage
}

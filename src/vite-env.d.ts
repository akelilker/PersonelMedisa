/// <reference types="vite/client" />

declare global {
  interface Window {
    appData?: import("./data/app-data.types").AppData;
  }
}

export {};

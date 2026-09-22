import { StrictMode } from "react";
import ReactDOM from "react-dom/client";
import { App } from "./app/App";
import { attachConnectivityListeners, initAppDataFromStorage } from "./data/data-manager";
import { initPwaInstallCapture } from "./lib/shell/pwa-install";
import { registerServiceWorkerOnce } from "./lib/shell/register-service-worker";
import "./styles/index.css";

initAppDataFromStorage();
attachConnectivityListeners();

// PWA install yüzeyi: `beforeinstallprompt` React mount'undan önce ve yalnızca
// bir kez tetiklendiği için yakalama bootstrap'ta yapılır. Service worker
// installability koşuludur (cache sahibi değildir).
initPwaInstallCapture();
registerServiceWorkerOnce();

ReactDOM.createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>
);

import { StrictMode } from "react";
import ReactDOM from "react-dom/client";
import { App } from "./app/App";
import {
  attachConnectivityListeners,
  initAppDataFromStorage,
  loadDataFromServer
} from "./data/data-manager";
import "./styles/index.css";

initAppDataFromStorage();
attachConnectivityListeners();
void loadDataFromServer();

ReactDOM.createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>
);

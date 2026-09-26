import { useEffect, useState } from "react";
import api from "./api/client";
import "./App.css";

function App() {
  const [message, setMessage] = useState("Connecting to Laravel...");
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    api
      .get("/health")
      .then((response) => {
        console.log("Laravel response:", response.data);

        setMessage(response.data.message);
        setConnected(true);
      })
      .catch((error) => {
        console.error("Laravel connection failed:", error);

        setMessage("Unable to connect to Laravel backend.");
        setConnected(false);
      });
  }, []);

  return (
    <main>
      <h1>IBM Bob AI Hackathon</h1>

      <div>
        {connected ? "🟢" : "🔴"} {message}
      </div>
    </main>
  );
}

export default App;
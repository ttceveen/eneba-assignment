import { useEffect, useState } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router'
import styles from './App.module.css'

function EnvironmentStatus() {
  const [status, setStatus] = useState('Проверка подключения…')

  useEffect(() => {
    const controller = new AbortController()
    const baseUrl = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
    fetch(`${baseUrl.replace(/\/$/, '')}/health`, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const data = await response.json()
        if (data.status !== 'ok' || data.database !== 'connected') throw new Error('Invalid health response')
        setStatus('Laravel API и PostgreSQL доступны')
      })
      .catch((error: Error) => {
        if (error.name !== 'AbortError') setStatus('API недоступен: ' + error.message)
      })
    return () => controller.abort()
  }, [])

  return <main className={styles.container}><h1>Eneba — локальное окружение</h1><p role="status">{status}</p></main>
}

export default function App() {
  return <BrowserRouter><Routes><Route path="/" element={<EnvironmentStatus />} /></Routes></BrowserRouter>
}

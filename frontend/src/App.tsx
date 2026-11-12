import { useLayoutEffect } from 'react'
import { BrowserRouter, Route, Routes, useLocation } from 'react-router'
import Header from './components/Header/Header'
import Footer from './components/Footer/Footer'
import Home from './pages/Home/Home'
import GameDetails from './pages/GameDetails/GameDetails'
import styles from './App.module.css'

function RouteScroll() {
  const location = useLocation()

  useLayoutEffect(() => {
    const previous = history.scrollRestoration
    history.scrollRestoration = 'manual'
    return () => { history.scrollRestoration = previous }
  }, [])

  useLayoutEffect(() => {
    if (location.pathname !== '/') window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
  }, [location.key, location.pathname])

  return null
}

export default function App() {
  return (
    <div className={styles.app}>
      <BrowserRouter>
        <RouteScroll />
        <Header />
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/games/:id" element={<GameDetails />} />
        </Routes>
        <Footer />
      </BrowserRouter>
    </div>
  )
}

import { BrowserRouter, Route, Routes } from 'react-router'
import Header from './components/Header/Header'
import Footer from './components/Footer/Footer'
import Home from './pages/Home/Home'
import GameDetails from './pages/GameDetails/GameDetails'
import styles from './App.module.css'

export default function App() {
  return (
    <div className={styles.app}>
      <BrowserRouter>
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

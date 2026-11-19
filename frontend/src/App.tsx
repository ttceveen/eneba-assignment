import { useLayoutEffect } from 'react'
import { BrowserRouter, matchPath, Route, Routes, useLocation } from 'react-router'
import Header from './components/Header/Header'
import Footer from './components/Footer/Footer'
import Home from './pages/Home/Home'
import HomeBanner from './components/HomeBanner/HomeBanner.tsx'
import GameDetails from './pages/GameDetails/GameDetails'
import styles from './App.module.css'
import CartProvider from './cart/CartProvider'
import Cart from './pages/Cart/Cart'
import AuthProvider from './auth/AuthProvider'
import RequireAuth from './auth/RequireAuth'
import Auth from './pages/Auth/Auth'
import ProtectedPage from './pages/Auth/ProtectedPage'
import Checkout from './pages/Checkout/Checkout'
import Payment from './pages/Checkout/Payment'
import Success from './pages/Checkout/Success'
import PayPalReturn from './pages/Checkout/PayPalReturn'

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

function AppContent() {
  const { pathname } = useLocation()
  const authPage = matchPath('/login', pathname) || matchPath('/register', pathname)

  return (
    <>
      <RouteScroll />
      {!authPage && <Header />}
      <Routes>
        <Route path="/" element={<><HomeBanner /><Home /></>} />
        <Route path="/games/:id" element={<GameDetails />} />
        <Route path="/cart" element={<Cart />} />
        <Route path="/login" element={<Auth key="login" mode="login" />} />
        <Route path="/register" element={<Auth key="register" mode="register" />} />
        <Route element={<RequireAuth />}>
          <Route path="/paypal/return" element={<PayPalReturn />} />
          <Route path="/checkout" element={<Checkout />} />
          <Route path="/orders/:id" element={<Payment />} />
          <Route path="/orders/:id/success" element={<Success />} />
          <Route path="/orders" element={<ProtectedPage title="Užsakymų istorija" />} />
        </Route>
      </Routes>
      {!authPage && <Footer />}
    </>
  )
}

export default function App() {
  return (
    <div className={styles.app}>
      <BrowserRouter>
        <AuthProvider>
          <CartProvider>
            <AppContent />
          </CartProvider>
        </AuthProvider>
      </BrowserRouter>
    </div>
  )
}

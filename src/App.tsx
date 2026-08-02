import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { ThemeProvider } from '@/context/ThemeContext'
import { AuthProvider } from '@/context/AuthContext'
import { Layout } from '@/components/layout/Layout'
import { ProtectedRoute } from '@/components/admin/ProtectedRoute'

import HomePage from '@/pages/HomePage'
import PortfolioPage from '@/pages/PortfolioPage'
import PortfolioDetailPage from '@/pages/PortfolioDetailPage'
import BlogPage from '@/pages/BlogPage'
import BlogPostPage from '@/pages/BlogPostPage'
import AboutPage from '@/pages/AboutPage'
import ContactPage from '@/pages/ContactPage'
import CustomPage from '@/pages/CustomPage'
import NotFoundPage from '@/pages/NotFoundPage'

// Code-split the admin panel (and its Supabase/editor dependencies) out of
// the public-site bundle, since most visitors never touch these routes.
const AdminLayout = lazy(() => import('@/components/admin/AdminLayout').then((m) => ({ default: m.AdminLayout })))
const AdminLoginPage = lazy(() => import('@/pages/admin/AdminLoginPage'))
const AdminDashboardPage = lazy(() => import('@/pages/admin/AdminDashboardPage'))
const AdminPortfolioPage = lazy(() => import('@/pages/admin/AdminPortfolioPage'))
const AdminBlogPage = lazy(() => import('@/pages/admin/AdminBlogPage'))
const AdminPagesPage = lazy(() => import('@/pages/admin/AdminPagesPage'))

function AdminFallback() {
  return <div className="flex min-h-screen items-center justify-center text-fg-muted">Loading admin…</div>
}

function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <BrowserRouter>
          <Suspense fallback={<AdminFallback />}>
            <Routes>
              <Route path="/admin/login" element={<AdminLoginPage />} />

              <Route element={<ProtectedRoute />}>
                <Route path="/admin" element={<AdminLayout />}>
                  <Route index element={<AdminDashboardPage />} />
                  <Route path="portfolio" element={<AdminPortfolioPage />} />
                  <Route path="blog" element={<AdminBlogPage />} />
                  <Route path="pages" element={<AdminPagesPage />} />
                </Route>
              </Route>

              <Route element={<Layout />}>
                <Route path="/" element={<HomePage />} />
                <Route path="/portfolio" element={<PortfolioPage />} />
                <Route path="/portfolio/:slug" element={<PortfolioDetailPage />} />
                <Route path="/blog" element={<BlogPage />} />
                <Route path="/blog/:slug" element={<BlogPostPage />} />
                <Route path="/about" element={<AboutPage />} />
                <Route path="/contact" element={<ContactPage />} />
                <Route path="/page/:slug" element={<CustomPage />} />
                <Route path="*" element={<NotFoundPage />} />
              </Route>
            </Routes>
          </Suspense>
        </BrowserRouter>
      </AuthProvider>
    </ThemeProvider>
  )
}

export default App

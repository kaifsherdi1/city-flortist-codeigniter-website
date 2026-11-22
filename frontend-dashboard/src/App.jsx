import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useSession } from './session.jsx';
import Layout, { Loading, Screen } from './components/Layout.jsx';
import Login, { ChoosePassword, ResetPassword } from './pages/Login.jsx';
import Install from './pages/Install.jsx';
import Dashboard from './pages/Dashboard.jsx';
import Products from './pages/Products.jsx';
import ProductEdit from './pages/ProductEdit.jsx';
import Offers from './pages/Offers.jsx';
import Orders from './pages/Orders.jsx';
import Reviews from './pages/Reviews.jsx';
import Settings from './pages/Settings.jsx';

// Bookmarks from the old PHP dashboard (/admin/products.php …) still land on the right page.
const OLD_PAGES = {
  'index.php': '/',
  'login.php': '/login',
  'install.php': '/install',
  'products.php': '/products',
  'product-edit.php': '/products/new',
  'offers.php': '/offers',
  'orders.php': '/orders',
  'settings.php': '/settings',
};

export default function App() {
  const session = useSession();
  const location = useLocation();

  const old = OLD_PAGES[location.pathname.replace(/^\//, '')];
  if (old) {
    const id = new URLSearchParams(location.search).get('id');
    return <Navigate to={old === '/products/new' && id ? `/products/${id}` : old} replace />;
  }

  if (session.loading) return <Screen><Loading /></Screen>;
  if (session.error && !session.installed) return <Screen><Loading error={session.error} retry={session.refresh} /></Screen>;

  if (!session.installed) {
    return (
      <Routes>
        <Route path="/install" element={<Install />} />
        <Route path="*" element={<Navigate to="/install" replace />} />
      </Routes>
    );
  }

  if (!session.admin) {
    return (
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="*" element={<Navigate to="/login" replace state={{ from: location }} />} />
      </Routes>
    );
  }

  // Logged in with the starting password: the server keeps everything locked until it's changed.
  if (session.mustChangePassword) return <ChoosePassword />;

  return (
    <Routes>
      <Route path="/login" element={<Navigate to={location.state?.from?.pathname || '/'} replace />} />
      <Route path="/install" element={<Navigate to="/" replace />} />
      <Route element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="products" element={<Products />} />
        <Route path="products/new" element={<ProductEdit />} />
        <Route path="products/:id" element={<ProductEdit />} />
        <Route path="offers" element={<Offers />} />
        <Route path="orders" element={<Orders />} />
        <Route path="reviews" element={<Reviews />} />
        <Route path="settings" element={<Settings />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}

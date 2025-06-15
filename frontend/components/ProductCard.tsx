import Image from 'next/image';
import { Product } from '@/types/product';

interface ProductCardProps {
  product: Product;
}

export default function ProductCard({ product }: ProductCardProps) {
  return (
    <div className="bg-white rounded-lg shadow-md overflow-hidden hover:shadow-lg transition-shadow duration-300">
      <div className="relative h-48 w-full">
        <Image
          src={product.image_url}
          alt={product.title}
          fill
          className="object-cover"
          sizes="(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 33vw"
        />
      </div>
      <div className="p-4">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 line-clamp-2">
          {product.title}
        </h2>
        <p className="text-xl font-bold text-blue-600">
          ${product.price.toFixed(2)}
        </p>
        <div className="mt-2 text-sm text-gray-500">
          <p>Source: {product.source_website}</p>
          <p className="text-xs mt-1">
            Added: {new Date(product.created_at).toLocaleDateString()}
          </p>
        </div>
      </div>
    </div>
  );
} 